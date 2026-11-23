<?php

namespace OmarRaafat\PaymobPayouts\Laravel\Events;

use OmarRaafat\PaymobPayouts\Laravel\Models\Payout;

/** Paymob refused the payout; no money left. It can be fixed and sent again with the same reference. */
class PayoutFailed
{
    /** @var Payout */
    public $payout;

    public function __construct(Payout $payout)
    {
        $this->payout = $payout;
    }
}
