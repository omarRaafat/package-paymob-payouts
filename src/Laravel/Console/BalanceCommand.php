<?php

namespace OmarRaafat\PaymobPayouts\Laravel\Console;

use Illuminate\Console\Command;
use OmarRaafat\PaymobPayouts\PaymobPayouts;

/** Shows the money available in the Paymob Payouts account; also a quick credentials check. */
class BalanceCommand extends Command
{
    protected $signature = 'paymob-payouts:balance';

    protected $description = 'Show the Paymob Payouts balance (and check the credentials work)';

    public function handle(PaymobPayouts $paymob): int
    {
        try {
            $balance = $paymob->balance();
        } catch (\Throwable $e) {
            $this->error('Could not get the balance: ' . $e->getMessage());
            return 1;
        }

        if ($balance === null) {
            $this->warn('Paymob answered, but without a recognisable balance:');
            $this->line(json_encode($paymob->budget(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        $this->info('Paymob Payouts balance: ' . number_format($balance, 2) . ' EGP');

        return 0;
    }
}
