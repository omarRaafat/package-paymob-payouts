<?php

namespace OmarRaafat\PaymobPayouts\Laravel\Events;

use OmarRaafat\PaymobPayouts\Laravel\Models\Payout;

/** The money was sent. Good place to mark invoices paid, email the receiver, etc. */
class PayoutSucceeded
{
    /** @var Payout */
    public $payout;

    public function __construct(Payout $payout)
    {
        $this->payout = $payout;
    }
}
