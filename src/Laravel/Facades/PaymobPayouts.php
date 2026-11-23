<?php

namespace OmarRaafat\PaymobPayouts\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \OmarRaafat\PaymobPayouts\DisbursementResult disburse(\OmarRaafat\PaymobPayouts\DisbursementRequest $request)
 * @method static \OmarRaafat\PaymobPayouts\DisbursementResult|null transactionStatus(?string $transactionId, ?string $clientReferenceId = null, bool $bankTransactions = false)
 * @method static array inquire(array $transactionIds, bool $bankTransactions = false)
 * @method static array inquireByReference(array $references, bool $bankTransactions = false)
 * @method static array budget()
 * @method static float|null balance()
 *
 * @see \OmarRaafat\PaymobPayouts\PaymobPayouts
 */
class PaymobPayouts extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'paymob-payouts';
    }
}
