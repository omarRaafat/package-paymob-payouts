<?php

/**
 * Plain PHP example: send one Vodafone Cash payout on Paymob STAGING.
 *
 *   PAYMOB_PAYOUTS_CLIENT_ID=... PAYMOB_PAYOUTS_CLIENT_SECRET=... \
 *   PAYMOB_PAYOUTS_USERNAME=... PAYMOB_PAYOUTS_PASSWORD=... \
 *   php examples/plain-php.php 01001234567 10
 */

require __DIR__ . '/../vendor/autoload.php';

use OmarRaafat\PaymobPayouts\Config;
use OmarRaafat\PaymobPayouts\DisbursementRequest;
use OmarRaafat\PaymobPayouts\Exceptions\PaymobPayoutsException;
use OmarRaafat\PaymobPayouts\Exceptions\ValidationException;
use OmarRaafat\PaymobPayouts\PaymobPayouts;
use OmarRaafat\PaymobPayouts\Token\FileTokenStore;

[$script, $msisdn, $amount] = $argv + [null, null, null];
if (!$msisdn || !$amount) {
    fwrite(STDERR, "Usage: php {$script} <wallet number> <amount>\n");
    exit(1);
}

$paymob = new PaymobPayouts(
    Config::fromArray([
        'client_id' => getenv('PAYMOB_PAYOUTS_CLIENT_ID'),
        'client_secret' => getenv('PAYMOB_PAYOUTS_CLIENT_SECRET'),
        'username' => getenv('PAYMOB_PAYOUTS_USERNAME'),
        'password' => getenv('PAYMOB_PAYOUTS_PASSWORD'),
        'environment' => 'staging',
    ]),
    new FileTokenStore(sys_get_temp_dir() . '/paymob-payouts'),
    null,
    function ($level, $message, $context) {
        echo strtoupper($level) . ': ' . $message . ' ' . json_encode($context) . PHP_EOL;
    }
);

try {
    echo 'Balance: ' . var_export($paymob->balance(), true) . PHP_EOL;

    $request = DisbursementRequest::vodafone($msisdn, (float) $amount);
    echo 'Reference (save this): ' . $request->clientReferenceId() . PHP_EOL;

    $result = $paymob->disburse($request);
    echo 'Result: ' . json_encode($result->toArray(), JSON_PRETTY_PRINT) . PHP_EOL;

    if (!$result->isFinal()) {
        echo "Not final yet. Check later with transactionStatus(); do not send again.\n";
    }
} catch (ValidationException $e) {
    echo 'Invalid: ' . json_encode($e->errors()) . PHP_EOL;
    exit(1);
} catch (PaymobPayoutsException $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
