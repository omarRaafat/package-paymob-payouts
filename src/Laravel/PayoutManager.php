<?php

namespace OmarRaafat\PaymobPayouts\Laravel;

use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use OmarRaafat\PaymobPayouts\DisbursementRequest;
use OmarRaafat\PaymobPayouts\DisbursementResult;
use OmarRaafat\PaymobPayouts\Exceptions\PaymobPayoutsException;
use OmarRaafat\PaymobPayouts\Laravel\Events\PayoutFailed;
use OmarRaafat\PaymobPayouts\Laravel\Events\PayoutPending;
use OmarRaafat\PaymobPayouts\Laravel\Events\PayoutSucceeded;
use OmarRaafat\PaymobPayouts\Laravel\Exceptions\DuplicatePayoutException;
use OmarRaafat\PaymobPayouts\Laravel\Models\Payout;
use OmarRaafat\PaymobPayouts\PaymobPayouts;
use OmarRaafat\PaymobPayouts\Status;

/**
 * Sends payouts safely and keeps a record of each one.
 *
 *   $payout = app(PayoutManager::class)->send(
 *       'invoice-1042',                      // your reference: never paid twice
 *       DisbursementRequest::vodafone('01001234567', 250),
 *       ['payable' => $merchant, 'created_by' => auth()->id(), 'meta' => ['order_ids' => [1, 2]]]
 *   );
 *
 * - The same reference is never sent twice while a payout for it is successful, pending,
 *   unknown or in progress (DuplicatePayoutException). Only after a FAILED one can it be retried.
 * - A row is saved before Paymob is called; the answer updates it.
 * - Timeouts become "unknown" and are settled later by reconcile() / `php artisan paymob-payouts:reconcile`.
 * - Events: PayoutSucceeded, PayoutFailed, PayoutPending (pending or unknown).
 */
class PayoutManager
{
    /** @var PaymobPayouts */
    private $paymob;

    public function __construct(PaymobPayouts $paymob)
    {
        $this->paymob = $paymob;
    }

    /**
     * @param array{payable?: Model, created_by?: int, meta?: array} $options
     * @throws DuplicatePayoutException|PaymobPayoutsException
     */
    public function send(string $reference, DisbursementRequest $request, array $options = []): Payout
    {
        $request->validate();

        return $this->withLock($reference, function () use ($reference, $request, $options) {
            $existing = Payout::where('reference', $reference)->whereIn('status', Payout::ACTIVE)->latest('id')->first();
            if ($existing) {
                throw new DuplicatePayoutException($existing);
            }

            $payout = new Payout([
                'reference' => $reference,
                'client_reference_id' => $request->clientReferenceId(),
                'issuer' => $request->issuer(),
                'destination' => (string) $request->destination(),
                'full_name' => $request->fullName(),
                'amount' => $request->amount(),
                'customer_bears_fees' => $request->customerBearsFees(),
                'status' => Payout::SENDING,
                'created_by' => $options['created_by'] ?? null,
                'meta' => $options['meta'] ?? null,
            ]);
            if (!empty($options['payable']) && $options['payable'] instanceof Model) {
                $payout->payable()->associate($options['payable']);
            }
            $payout->save();

            try {
                $result = $this->paymob->disburse($request);
            } catch (PaymobPayoutsException $e) {
                // Login refused / invalid request: Paymob was not asked to pay, so this is a clean failure.
                $payout->fill([
                    'status' => Status::FAILED,
                    'status_description' => $e->getMessage(),
                    'completed_at' => now(),
                ])->save();
                Event::dispatch(new PayoutFailed($payout));

                throw $e;
            }

            $this->apply($payout, $result);

            return $payout;
        });
    }

    /**
     * Ask Paymob about a pending / unknown / interrupted payout and store the answer.
     * Returns true when the status changed.
     */
    public function reconcile(Payout $payout): bool
    {
        if (!$payout->isInFlight()) {
            return false;
        }

        $before = $payout->status;
        $result = $this->paymob->transactionStatus($payout->transaction_id, $payout->client_reference_id, $payout->isBankTransaction());

        $payout->checks++;
        $payout->last_checked_at = now();

        if (!$result) {
            // Paymob has no record of it (yet). It is never marked failed automatically: if the
            // lookup missed it, a resend would pay twice. A person checks it in the Paymob
            // dashboard and calls markFailed() / markSuccessful().
            if ($payout->status === Payout::SENDING) {
                $payout->status = Status::UNKNOWN;
                $payout->status_description = 'Not found at Paymob. Check the Paymob dashboard (client reference '
                    . $payout->client_reference_id . ') before sending again.';
                $payout->save();

                return true;
            }
            $payout->save();

            return false;
        }

        $this->apply($payout, $result, false);

        return $payout->status !== $before;
    }

    /**
     * Settle a payout by hand after checking it in the Paymob dashboard (e.g. an "unknown" one
     * Paymob's inquiry could not find). Marking it failed allows sending the reference again.
     */
    public function markFailed(Payout $payout, string $note): Payout
    {
        $payout->fill(['status' => Status::FAILED, 'status_description' => $note, 'completed_at' => now()])->save();
        Event::dispatch(new PayoutFailed($payout));

        return $payout;
    }

    public function markSuccessful(Payout $payout, string $note, ?string $transactionId = null): Payout
    {
        $payout->fill([
            'status' => Status::SUCCESSFUL,
            'status_description' => $note,
            'transaction_id' => $transactionId ?: $payout->transaction_id,
            'completed_at' => now(),
        ])->save();
        Event::dispatch(new PayoutSucceeded($payout));

        return $payout;
    }

    private function apply(Payout $payout, DisbursementResult $result, bool $fromSend = true): void
    {
        $payout->fill([
            'status' => $result->status(),
            'transaction_id' => $result->transactionId() ?: $payout->transaction_id,
            'paymob_status' => $result->paymobStatus(),
            'status_code' => $result->statusCode(),
            'status_description' => $result->statusDescription(),
            'http_status' => $result->httpStatus() ?: $payout->http_status,
            'response' => $result->raw() ?: $payout->response,
            'completed_at' => $result->isFinal() ? now() : null,
        ])->save();

        if ($result->isSuccessful()) {
            Event::dispatch(new PayoutSucceeded($payout));
        } elseif ($result->isFailed()) {
            Event::dispatch(new PayoutFailed($payout));
        } elseif ($fromSend) {
            Event::dispatch(new PayoutPending($payout));
        }
    }

    /** One payout per reference at a time, across requests and queue workers. */
    private function withLock(string $reference, callable $callback)
    {
        $store = Cache::getStore();
        if (!$store instanceof LockProvider) {
            return $callback();
        }

        $lock = Cache::lock('paymob-payout:' . sha1($reference), 120);
        if (!$lock->get()) {
            throw new DuplicatePayoutException(null, 'Another payout for "' . $reference . '" is being sent right now.');
        }

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }
}
