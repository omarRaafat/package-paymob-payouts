<?php

namespace OmarRaafat\PaymobPayouts\Laravel\Exceptions;

use OmarRaafat\PaymobPayouts\Exceptions\PaymobPayoutsException;
use OmarRaafat\PaymobPayouts\Laravel\Models\Payout;

/** A payout for this reference is already successful, pending, unknown or being sent. Nothing was sent. */
class DuplicatePayoutException extends PaymobPayoutsException
{
    /** @var Payout|null */
    private $existing;

    public function __construct(?Payout $existing, ?string $message = null)
    {
        $this->existing = $existing;
        parent::__construct($message ?: sprintf(
            'Payout "%s" was already sent (status: %s, payout #%d). It will not be sent again.',
            $existing ? $existing->reference : '',
            $existing ? $existing->status : '',
            $existing ? $existing->id : 0
        ));
    }

    public function existing(): ?Payout
    {
        return $this->existing;
    }
}
