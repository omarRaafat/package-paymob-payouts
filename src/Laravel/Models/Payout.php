<?php

namespace OmarRaafat\PaymobPayouts\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use OmarRaafat\PaymobPayouts\Issuer;
use OmarRaafat\PaymobPayouts\Msisdn;
use OmarRaafat\PaymobPayouts\Status;

/**
 * @property int $id
 * @property string $reference
 * @property string $client_reference_id
 * @property string $issuer
 * @property string $destination
 * @property string|null $full_name
 * @property string $amount
 * @property string $status
 * @property string|null $transaction_id
 * @property string|null $status_description
 */
class Payout extends Model
{
    /** Row written, Paymob not answered yet (or the process stopped mid-call). */
    public const SENDING = 'sending';

    protected $guarded = ['id'];

    protected $casts = [
        'amount' => 'decimal:2',
        'customer_bears_fees' => 'boolean',
        'response' => 'array',
        'meta' => 'array',
        'checks' => 'integer',
        'http_status' => 'integer',
        'last_checked_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /** Statuses that block another payout for the same reference. */
    public const ACTIVE = [Status::SUCCESSFUL, Status::PENDING, Status::UNKNOWN, self::SENDING];

    public function getTable()
    {
        return config('paymob-payouts.table', 'paymob_payouts');
    }

    public function payable()
    {
        return $this->morphTo();
    }

    public function scopeNeedsCheck($query, int $olderThanMinutes = 2)
    {
        return $query->whereIn('status', [Status::PENDING, Status::UNKNOWN, self::SENDING])
            ->where('updated_at', '<=', now()->subMinutes($olderThanMinutes));
    }

    public function isSuccessful(): bool
    {
        return $this->status === Status::SUCCESSFUL;
    }

    public function isFailed(): bool
    {
        return $this->status === Status::FAILED;
    }

    /** Not final yet: pending, unknown, or interrupted while sending. */
    public function isInFlight(): bool
    {
        return in_array($this->status, [Status::PENDING, Status::UNKNOWN, self::SENDING], true);
    }

    public function isBankTransaction(): bool
    {
        return in_array($this->issuer, [Issuer::BANK_CARD, Issuer::INSTANT_BANK], true);
    }

    public function issuerLabel(): string
    {
        return Issuer::label($this->issuer);
    }

    /** Destination with the middle hidden, for screens and emails. */
    public function maskedDestination(): string
    {
        return Issuer::isWallet($this->issuer)
            ? Msisdn::mask($this->destination)
            : '****' . substr($this->destination, -4);
    }
}
