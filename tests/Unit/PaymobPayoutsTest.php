<?php

namespace OmarRaafat\PaymobPayouts\Tests\Unit;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use OmarRaafat\PaymobPayouts\DisbursementRequest;
use OmarRaafat\PaymobPayouts\Exceptions\ApiException;
use OmarRaafat\PaymobPayouts\Exceptions\AuthenticationException;
use OmarRaafat\PaymobPayouts\Exceptions\ValidationException;
use OmarRaafat\PaymobPayouts\Status;
use OmarRaafat\PaymobPayouts\Tests\FakePaymob;
use OmarRaafat\PaymobPayouts\Token\InMemoryTokenStore;

class PaymobPayoutsTest extends TestCase
{
    private function success(array $extra = []): Response
    {
        return FakePaymob::json(200, $extra + [
            'transaction_id' => 'tx-1001',
            'issuer' => 'vodafone',
            'msisdn' => '01001234567',
            'amount' => '250.00',
            'disbursement_status' => 'successful',
            'status_code' => '200',
            'status_description' => 'Disbursement completed successfully',
        ]);
    }

    public function test_it_logs_in_and_sends_a_vodafone_payout_exactly_as_paymob_expects(): void
    {
        $fake = (new FakePaymob())->queueToken()->queue($this->success());
        $request = DisbursementRequest::vodafone('+20 100 123 4567', 250)->withClientReferenceId('ref-1');

        $result = $fake->client->disburse($request);

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('tx-1001', $result->transactionId());
        $this->assertSame('ref-1', $result->clientReferenceId());

        // 1) token: form-encoded password grant
        $this->assertSame('POST', $fake->request(0)->getMethod());
        $this->assertSame('https://stagingpayouts.paymobsolutions.com/api/secure/o/token/', (string) $fake->request(0)->getUri());
        $this->assertSame(
            ['grant_type' => 'password', 'username' => 'user', 'password' => 'pass', 'client_id' => 'cid', 'client_secret' => 'csecret'],
            $fake->formBody(0)
        );

        // 2) disburse: JSON with bearer token, normalised number, string amount, reference
        $this->assertSame('https://stagingpayouts.paymobsolutions.com/api/secure/disburse/', (string) $fake->request(1)->getUri());
        $this->assertSame('Bearer access-1', $fake->request(1)->getHeaderLine('Authorization'));
        $this->assertSame([
            'issuer' => 'vodafone',
            'amount' => '250.00',
            'msisdn' => '01001234567',
            'client_reference_id' => 'ref-1',
            'reference' => 'ref-1',
            'customer_bears_fees' => false,
        ], $fake->jsonBody(1));
    }

    /** The exact answer Paymob staging gave for a 10 EGP Vodafone payout (2026-11-23). */
    private function stagingSuccess(): array
    {
        return [
            'transaction_id' => '77d80d4e-6fae-4a5c-a46f-39b972cd22be', 'issuer' => 'vodafone', 'msisdn' => '01023456789',
            'amount' => 10.0, 'full_name' => '', 'disbursement_status' => 'successful', 'status_code' => '200',
            'fees' => 0.225, 'vat' => 0.0315, 'reference_id' => '', 'status_description' => 'amount 10.00 is disbursed successfully',
            'aman_cashing_details' => null, 'client_transaction_reference' => '400f5a82-4b68-4d21-ac02-d93a119f7a05',
            'created_at' => '2026-11-23 12:36:39.408492', 'updated_at' => '2026-11-23 12:36:39.436588',
            'accept_balance_transfer_id' => null, 'reference' => '400f5a82-4b68-4d21-ac02-d93a119f7a05', 'comment' => '', 'bank_name' => null,
        ];
    }

    public function test_the_real_staging_answer_is_read_correctly(): void
    {
        $fake = (new FakePaymob())->queueToken()->queue(FakePaymob::json(200, $this->stagingSuccess()));

        $result = $fake->client->disburse(DisbursementRequest::vodafone('01023456789', 10)->withClientReferenceId('400f5a82-4b68-4d21-ac02-d93a119f7a05'));

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('77d80d4e-6fae-4a5c-a46f-39b972cd22be', $result->transactionId());
        $this->assertSame('400f5a82-4b68-4d21-ac02-d93a119f7a05', $result->clientReferenceId());
        $this->assertSame(0.225, $result->fees());
        $this->assertSame(0.0315, $result->vat());
    }

    public function test_a_timed_out_payout_is_found_by_reference_with_the_real_inquiry_shape(): void
    {
        $fake = (new FakePaymob())->queueToken()->queue(FakePaymob::json(200, [
            'count' => 1, 'next' => null, 'previous' => null, 'results' => [$this->stagingSuccess()],
        ]));

        $result = $fake->client->transactionStatus(null, '400f5a82-4b68-4d21-ac02-d93a119f7a05');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('77d80d4e-6fae-4a5c-a46f-39b972cd22be', $result->transactionId());
    }

    public function test_an_empty_inquiry_means_not_found(): void
    {
        $fake = (new FakePaymob())->queueToken()->queue(FakePaymob::json(200, ['count' => 0, 'next' => null, 'previous' => null, 'results' => []]));

        $this->assertNull($fake->client->transactionStatus(null, 'never-sent'));
    }

    public function test_the_token_is_reused_until_it_expires(): void
    {
        $fake = (new FakePaymob())->queueToken()->queue($this->success(), $this->success());

        $fake->client->disburse(DisbursementRequest::vodafone('01001234567', 10));
        $fake->client->disburse(DisbursementRequest::vodafone('01001234567', 20));

        $this->assertCount(3, $fake->history, 'one login, two payouts');
    }

    public function test_an_expired_token_is_renewed_with_the_refresh_token(): void
    {
        $store = new InMemoryTokenStore();
        $store->put('ignored', [], 0);
        $fake = new FakePaymob($store);
        $fake->queueToken('old', 'refresh-old', 30)        // expires inside the safety margin
            ->queue($this->success())
            ->queueToken('new', 'refresh-new')
            ->queue($this->success());

        $fake->client->disburse(DisbursementRequest::vodafone('01001234567', 10));
        $fake->client->disburse(DisbursementRequest::vodafone('01001234567', 20));

        $this->assertSame(['grant_type' => 'refresh_token', 'refresh_token' => 'refresh-old', 'client_id' => 'cid', 'client_secret' => 'csecret'], $fake->formBody(2));
        $this->assertSame('Bearer new', $fake->request(3)->getHeaderLine('Authorization'));
    }

    public function test_a_rejected_token_triggers_one_new_login_and_retry(): void
    {
        $fake = (new FakePaymob())->queueToken('stale')
            ->queue(FakePaymob::json(401, ['detail' => 'Invalid token']))
            ->queueToken('fresh')
            ->queue($this->success());

        $result = $fake->client->disburse(DisbursementRequest::vodafone('01001234567', 10));

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('Bearer fresh', $fake->request(3)->getHeaderLine('Authorization'));
    }

    public function test_wrong_credentials_throw_and_nothing_is_sent(): void
    {
        $fake = (new FakePaymob())->queue(FakePaymob::json(400, ['error' => 'invalid_grant', 'error_description' => 'Invalid credentials given.']));

        try {
            $fake->client->disburse(DisbursementRequest::vodafone('01001234567', 10));
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertStringContainsString('Invalid credentials given.', $e->getMessage());
        }
        $this->assertCount(1, $fake->history, 'only the login call');
    }

    public function test_a_refused_payout_is_failed(): void
    {
        $fake = (new FakePaymob())->queueToken()->queue(FakePaymob::json(200, [
            'disbursement_status' => 'failed',
            'status_code' => '618',
            'status_description' => 'Insufficient budget',
        ]));

        $result = $fake->client->disburse(DisbursementRequest::vodafone('01001234567', 10));

        $this->assertTrue($result->isFailed());
        $this->assertSame('618', $result->statusCode());
        $this->assertSame('Insufficient budget', $result->statusDescription());
    }

    public function test_pending_stays_pending(): void
    {
        $fake = (new FakePaymob())->queueToken()->queue(FakePaymob::json(200, ['transaction_id' => 'tx-9', 'disbursement_status' => 'pending']));

        $result = $fake->client->disburse(DisbursementRequest::vodafone('01001234567', 10));

        $this->assertTrue($result->isPending());
        $this->assertFalse($result->isFinal());
    }

    public function test_a_timeout_is_unknown_never_failed(): void
    {
        $fake = (new FakePaymob())->queueToken()->queue(
            new ConnectException('cURL error 28: Operation timed out', new Request('POST', 'https://x/api/secure/disburse/'))
        );

        $result = $fake->client->disburse(DisbursementRequest::vodafone('01001234567', 10)->withClientReferenceId('ref-timeout'));

        $this->assertTrue($result->isUnknown());
        $this->assertSame('ref-timeout', $result->clientReferenceId());
        $this->assertSame(Status::UNKNOWN, $result->status());
    }

    public function test_a_server_error_without_a_status_is_unknown(): void
    {
        $fake = (new FakePaymob())->queueToken()->queue(new Response(502, [], '<html>Bad gateway</html>'));

        $result = $fake->client->disburse(DisbursementRequest::vodafone('01001234567', 10));

        $this->assertTrue($result->isUnknown());
        $this->assertSame(502, $result->httpStatus());
    }

    public function test_a_validation_error_from_paymob_is_failed(): void
    {
        $fake = (new FakePaymob())->queueToken()->queue(FakePaymob::json(400, ['msisdn' => ['Invalid wallet number']]));

        $this->assertTrue($fake->client->disburse(DisbursementRequest::vodafone('01001234567', 10))->isFailed());
    }

    public function test_per_field_errors_from_paymob_become_the_description(): void
    {
        // Real Paymob staging answer for a 1 EGP Vodafone payout.
        $fake = (new FakePaymob())->queueToken()->queue(FakePaymob::json(400, [
            'disbursement_status' => 'failed',
            'status_description' => ['amount' => ['Ensure this value is greater than or equal to 5.']],
            'status_code' => '400',
        ]));

        $result = $fake->client->disburse(DisbursementRequest::vodafone('01001234567', 1));

        $this->assertTrue($result->isFailed());
        $this->assertSame('amount: Ensure this value is greater than or equal to 5.', $result->statusDescription());
    }

    public function test_invalid_requests_are_stopped_before_any_call(): void
    {
        $fake = new FakePaymob();

        $this->expectException(ValidationException::class);
        try {
            $fake->client->disburse(DisbursementRequest::vodafone('12345', 0));
        } finally {
            $this->assertCount(0, $fake->history);
        }
    }

    public function test_inquiry_by_transaction_id_returns_the_current_status(): void
    {
        $fake = (new FakePaymob())->queueToken()->queue(FakePaymob::json(200, [
            ['transaction_id' => 'tx-1', 'disbursement_status' => 'successful', 'status_code' => '200'],
        ]));

        $result = $fake->client->transactionStatus('tx-1');

        $this->assertTrue($result->isSuccessful());
        $this->assertSame('GET', $fake->request(1)->getMethod());
        $this->assertStringEndsWith('/api/secure/transaction/inquire/', (string) $fake->request(1)->getUri());
        $this->assertSame(['transactions_ids_list' => ['tx-1']], $fake->jsonBody(1));
    }

    public function test_inquiry_falls_back_to_the_client_reference(): void
    {
        $fake = (new FakePaymob())->queueToken()->queue(FakePaymob::json(200, [
            'results' => [['transaction_id' => 'tx-7', 'client_reference_id' => 'ref-7', 'disbursement_status' => 'successful']],
        ]));

        $result = $fake->client->transactionStatus(null, 'ref-7');

        $this->assertSame('tx-7', $result->transactionId());
        $this->assertSame(['references_list' => ['ref-7'], 'bank_transactions' => false], $fake->jsonBody(1));
    }

    public function test_balance_reads_the_budget_answer(): void
    {
        $fake = (new FakePaymob())->queueToken()->queue(FakePaymob::json(200, ['current_budget' => 'Your current budget is 15,250.50 LE']));

        $this->assertSame(15250.50, $fake->client->balance());
        $this->assertStringEndsWith('/api/secure/budget/inquire/', (string) $fake->request(1)->getUri());
    }

    public function test_inquiry_errors_throw(): void
    {
        $fake = (new FakePaymob())->queueToken()->queue(FakePaymob::json(500, ['detail' => 'boom']));

        $this->expectException(ApiException::class);
        $fake->client->inquire(['tx-1']);
    }

    public function test_logs_never_contain_the_full_number(): void
    {
        $fake = (new FakePaymob())->queueToken()->queue($this->success());

        $fake->client->disburse(DisbursementRequest::vodafone('01001234567', 10));

        $this->assertStringNotContainsString('01001234567', json_encode($fake->logs[0]));
        $this->assertStringContainsString('010****4567', json_encode($fake->logs[0]));
    }
}
