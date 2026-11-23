<?php

namespace OmarRaafat\PaymobPayouts\Tests\Laravel;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;
use OmarRaafat\PaymobPayouts\DisbursementRequest;
use OmarRaafat\PaymobPayouts\Exceptions\AuthenticationException;
use OmarRaafat\PaymobPayouts\Laravel\Events\PayoutFailed;
use OmarRaafat\PaymobPayouts\Laravel\Events\PayoutPending;
use OmarRaafat\PaymobPayouts\Laravel\Events\PayoutSucceeded;
use OmarRaafat\PaymobPayouts\Laravel\Exceptions\DuplicatePayoutException;
use OmarRaafat\PaymobPayouts\Laravel\Models\Payout;
use OmarRaafat\PaymobPayouts\Laravel\PaymobPayoutsServiceProvider;
use OmarRaafat\PaymobPayouts\Laravel\PayoutManager;
use OmarRaafat\PaymobPayouts\PaymobPayouts;
use OmarRaafat\PaymobPayouts\Status;
use OmarRaafat\PaymobPayouts\Tests\FakePaymob;

class PayoutManagerTest extends TestCase
{
    /** @var FakePaymob */
    private $fake;
    /** @var PayoutManager */
    private $manager;

    protected function getPackageProviders($app)
    {
        return [PaymobPayoutsServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('paymob-payouts.client_id', 'cid');
        $app['config']->set('paymob-payouts.client_secret', 'cs');
        $app['config']->set('paymob-payouts.username', 'u');
        $app['config']->set('paymob-payouts.password', 'p');
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../database/migrations/2026_01_01_000000_create_paymob_payouts_table.php';
        (new \CreatePaymobPayoutsTable())->up();

        $this->fake = new FakePaymob();
        $this->app->instance(PaymobPayouts::class, $this->fake->client);
        $this->manager = new PayoutManager($this->fake->client);
        Event::fake();
    }

    private function vodafone(float $amount = 250): DisbursementRequest
    {
        return DisbursementRequest::vodafone('01001234567', $amount);
    }

    public function test_successful_payout_is_recorded_and_announced(): void
    {
        $this->fake->queueToken()->queue(FakePaymob::json(200, ['transaction_id' => 'tx-1', 'disbursement_status' => 'successful', 'status_code' => '200']));

        $payout = $this->manager->send('invoice-15', $this->vodafone(), ['created_by' => 9, 'meta' => ['order_ids' => [1, 2]]]);

        $this->assertSame(Status::SUCCESSFUL, $payout->status);
        $this->assertSame('tx-1', $payout->transaction_id);
        $this->assertSame('250.00', (string) $payout->amount);
        $this->assertSame('01001234567', $payout->destination);
        $this->assertSame('010****4567', $payout->maskedDestination());
        $this->assertSame([1, 2], $payout->meta['order_ids']);
        $this->assertNotNull($payout->completed_at);
        Event::assertDispatched(PayoutSucceeded::class);
    }

    public function test_the_same_reference_is_never_paid_twice(): void
    {
        $this->fake->queueToken()->queue(FakePaymob::json(200, ['transaction_id' => 'tx-1', 'disbursement_status' => 'successful']));
        $this->manager->send('invoice-15', $this->vodafone());

        try {
            $this->manager->send('invoice-15', $this->vodafone());
            $this->fail('Expected DuplicatePayoutException');
        } catch (DuplicatePayoutException $e) {
            $this->assertSame(Status::SUCCESSFUL, $e->existing()->status);
        }
        $this->assertCount(2, $this->fake->history, 'second payout never reached Paymob');
        $this->assertSame(1, Payout::count());
    }

    public function test_a_failed_payout_can_be_sent_again(): void
    {
        $this->fake->queueToken()
            ->queue(FakePaymob::json(200, ['disbursement_status' => 'failed', 'status_description' => 'Wallet not registered']))
            ->queue(FakePaymob::json(200, ['transaction_id' => 'tx-2', 'disbursement_status' => 'successful']));

        $first = $this->manager->send('ref-x', $this->vodafone());
        $second = $this->manager->send('ref-x', $this->vodafone());

        $this->assertSame(Status::FAILED, $first->status);
        $this->assertSame(Status::SUCCESSFUL, $second->status);
        $this->assertNotSame($first->client_reference_id, $second->client_reference_id);
        Event::assertDispatched(PayoutFailed::class);
    }

    public function test_a_timeout_blocks_resending_until_reconciled(): void
    {
        $this->fake->queueToken()->queue(new ConnectException('timed out', new Request('POST', 'x')));

        $payout = $this->manager->send('ref-t', $this->vodafone());

        $this->assertSame(Status::UNKNOWN, $payout->status);
        Event::assertDispatched(PayoutPending::class);

        $this->expectException(DuplicatePayoutException::class);
        $this->manager->send('ref-t', $this->vodafone());
    }

    public function test_reconcile_settles_an_unknown_payout_by_its_reference(): void
    {
        $this->fake->queueToken()->queue(new ConnectException('timed out', new Request('POST', 'x')));
        $payout = $this->manager->send('ref-r', $this->vodafone());

        $this->fake->queue(FakePaymob::json(200, [
            ['transaction_id' => 'tx-55', 'client_reference_id' => $payout->client_reference_id, 'disbursement_status' => 'successful'],
        ]));

        $this->assertTrue($this->manager->reconcile($payout->fresh()));
        $payout->refresh();
        $this->assertSame(Status::SUCCESSFUL, $payout->status);
        $this->assertSame('tx-55', $payout->transaction_id);
        $this->assertSame(1, $payout->checks);
        Event::assertDispatched(PayoutSucceeded::class);
    }

    public function test_an_interrupted_payout_not_found_at_paymob_is_never_auto_failed(): void
    {
        $payout = Payout::create([
            'reference' => 'ref-crash', 'client_reference_id' => 'c-1', 'issuer' => 'vodafone',
            'destination' => '01001234567', 'amount' => 10, 'status' => Payout::SENDING,
        ]);
        $this->fake->queueToken()->queue(FakePaymob::json(200, []));

        $this->manager->reconcile($payout);

        $this->assertSame(Status::UNKNOWN, $payout->fresh()->status);
        $this->expectException(DuplicatePayoutException::class);
        $this->manager->send('ref-crash', $this->vodafone());
    }

    public function test_manual_settlement_allows_a_resend(): void
    {
        $payout = Payout::create([
            'reference' => 'ref-m', 'client_reference_id' => 'c-2', 'issuer' => 'vodafone',
            'destination' => '01001234567', 'amount' => 10, 'status' => Status::UNKNOWN,
        ]);

        $this->manager->markFailed($payout, 'Checked in Paymob dashboard: not sent.');
        $this->fake->queueToken()->queue(FakePaymob::json(200, ['transaction_id' => 'tx-3', 'disbursement_status' => 'successful']));

        $this->assertSame(Status::SUCCESSFUL, $this->manager->send('ref-m', $this->vodafone())->status);
    }

    public function test_login_failure_is_recorded_as_failed_and_rethrown(): void
    {
        $this->fake->queue(FakePaymob::json(401, ['error' => 'invalid_client']));

        try {
            $this->manager->send('ref-auth', $this->vodafone());
            $this->fail('Expected AuthenticationException');
        } catch (AuthenticationException $e) {
            $this->assertSame(Status::FAILED, Payout::where('reference', 'ref-auth')->first()->status);
        }
    }

    public function test_the_reconcile_command_runs(): void
    {
        $this->fake->queueToken()->queue(FakePaymob::json(200, ['transaction_id' => 'tx-p', 'disbursement_status' => 'pending']));
        $payout = $this->manager->send('ref-cmd', $this->vodafone());
        Payout::whereKey($payout->id)->update(['updated_at' => now()->subMinutes(10)]);

        $this->fake->queue(FakePaymob::json(200, [['transaction_id' => 'tx-p', 'disbursement_status' => 'successful']]));

        $this->artisan('paymob-payouts:reconcile')->assertExitCode(0);
        $this->assertSame(Status::SUCCESSFUL, $payout->fresh()->status);
    }

    public function test_the_service_provider_builds_the_client_from_config(): void
    {
        $this->app->forgetInstance(PaymobPayouts::class);

        $this->assertInstanceOf(PaymobPayouts::class, $this->app->make(PaymobPayouts::class));
        $this->assertInstanceOf(PayoutManager::class, $this->app->make(PayoutManager::class));
    }
}
