<?php

namespace OmarRaafat\PaymobPayouts\Tests\Unit;

use PHPUnit\Framework\TestCase;
use OmarRaafat\PaymobPayouts\DisbursementRequest;
use OmarRaafat\PaymobPayouts\Exceptions\ValidationException;
use OmarRaafat\PaymobPayouts\Issuer;
use OmarRaafat\PaymobPayouts\Msisdn;

class DisbursementRequestTest extends TestCase
{
    private function errors(DisbursementRequest $request): array
    {
        try {
            $request->validate();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    public function test_every_request_gets_a_unique_uuid_reference(): void
    {
        $a = DisbursementRequest::vodafone('01001234567', 10);
        $b = DisbursementRequest::vodafone('01001234567', 10);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $a->clientReferenceId());
        $this->assertNotSame($a->clientReferenceId(), $b->clientReferenceId());
    }

    public function test_orange_and_bank_wallets_need_the_full_name(): void
    {
        $this->assertArrayHasKey('full_name', $this->errors(DisbursementRequest::wallet(Issuer::ORANGE, '01201234567', 10)));
        $this->assertArrayHasKey('full_name', $this->errors(DisbursementRequest::wallet(Issuer::BANK_WALLET, '01001234567', 10)));
        $this->assertSame([], $this->errors(DisbursementRequest::orange('01201234567', 10, 'Ahmed Ali')));
    }

    public function test_bank_card_body(): void
    {
        $request = DisbursementRequest::bankCard('1111 2222 3333 4444', 'cib', 'Ahmed Ali', 1500, 'salary', 'March salary')
            ->withClientReferenceId('r')->withCustomerBearsFees();

        $this->assertSame([], $this->errors($request));
        $this->assertSame([
            'issuer' => 'bank_card',
            'amount' => '1500.00',
            'bank_card_number' => '1111222233334444',
            'bank_code' => 'CIB',
            'full_name' => 'Ahmed Ali',
            'bank_transaction_type' => 'salary',
            'comment' => 'March salary',
            'client_reference_id' => 'r',
            'reference' => 'r',
            'customer_bears_fees' => true,
        ], $request->toArray());
    }

    public function test_bank_card_transaction_type_is_checked(): void
    {
        $this->assertArrayHasKey('bank_transaction_type', $this->errors(DisbursementRequest::bankCard('1111', 'CIB', 'A', 10, 'gift')));
    }

    public function test_instant_bank_minimum(): void
    {
        $this->assertArrayHasKey('amount', $this->errors(DisbursementRequest::instantBank('EG38001900050000', 'CIB', 'Ahmed', 100)));
        $this->assertSame([], $this->errors(DisbursementRequest::instantBank('EG38001900050000', 'CIB', 'Ahmed', 112)));
    }

    public function test_post_needs_a_valid_national_id(): void
    {
        $this->assertArrayHasKey('national_id', $this->errors(DisbursementRequest::post('123', 'Ahmed', 10)));
        $this->assertSame([], $this->errors(DisbursementRequest::post('29001010000000', 'Ahmed', 10)));
    }

    public function test_amount_and_number_checks(): void
    {
        $errors = $this->errors(DisbursementRequest::vodafone('0100', -5));

        $this->assertArrayHasKey('amount', $errors);
        $this->assertArrayHasKey('msisdn', $errors);
    }

    public function test_unknown_issuer(): void
    {
        $this->assertArrayHasKey('issuer', $this->errors(DisbursementRequest::wallet('aman', '01001234567', 10)));
    }

    public function test_safe_array_hides_destinations(): void
    {
        $this->assertSame('010****4567', DisbursementRequest::vodafone('01001234567', 1)->toSafeArray()['msisdn']);
        $this->assertSame('****4444', DisbursementRequest::bankCard('1111222233334444', 'CIB', 'A', 1)->toSafeArray()['bank_card_number']);
    }

    /** @dataProvider numbers */
    public function test_msisdn_normalisation(string $input, string $expected, bool $valid): void
    {
        $this->assertSame($expected, Msisdn::normalize($input));
        $this->assertSame($valid, Msisdn::isValid($input));
    }

    public static function numbers(): array
    {
        return [
            ['01001234567', '01001234567', true],
            ['+20 100 123 4567', '01001234567', true],
            ['00201101234567', '01101234567', true],
            ['201201234567', '01201234567', true],
            ['1501234567', '01501234567', true],
            ['٠١٠٠١٢٣٤٥٦٧', '01001234567', true],
            ['01301234567', '01301234567', false],
            ['0100123', '0100123', false],
        ];
    }

    public function test_issuer_guess_from_prefix(): void
    {
        $this->assertSame(Issuer::VODAFONE, Issuer::guessFromMsisdn('01001234567'));
        $this->assertSame(Issuer::ETISALAT, Issuer::guessFromMsisdn('+201101234567'));
        $this->assertSame(Issuer::ORANGE, Issuer::guessFromMsisdn('01201234567'));
        $this->assertNull(Issuer::guessFromMsisdn('01501234567'));
    }
}
