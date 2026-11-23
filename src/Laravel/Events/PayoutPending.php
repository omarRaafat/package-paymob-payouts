<?php

namespace OmarRaafat\PaymobPayouts\Laravel\Events;

use OmarRaafat\PaymobPayouts\Laravel\Models\Payout;

/**
 * Not final yet: Paymob said "pending", or did not answer (status "unknown").
 * Do not resend; `paymob-payouts:reconcile` settles it and fires PayoutSucceeded / PayoutFailed.
 */
class PayoutPending
{
    /** @var Payout */
    public $payout;

    public function __construct(Payout $payout)
    {
        $this->payout = $payout;
    }
}
