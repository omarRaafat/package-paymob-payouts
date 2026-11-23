<?php

namespace OmarRaafat\PaymobPayouts\Laravel\Console;

use Illuminate\Console\Command;
use OmarRaafat\PaymobPayouts\Laravel\Models\Payout;
use OmarRaafat\PaymobPayouts\Laravel\PayoutManager;

/**
 * Settles payouts that are pending, unknown (timed out) or were interrupted while sending.
 * Schedule it every few minutes:
 *
 *   $schedule->command('paymob-payouts:reconcile')->everyFiveMinutes()->withoutOverlapping();
 */
class ReconcileCommand extends Command
{
    protected $signature = 'paymob-payouts:reconcile
                            {--older-than= : Only payouts not updated for this many minutes (default from config)}
                            {--limit=100 : Maximum payouts to check in one run}';

    protected $description = 'Check pending / unknown Paymob payouts with Paymob and record the final result';

    public function handle(PayoutManager $manager): int
    {
        $olderThan = (int) ($this->option('older-than') ?? config('paymob-payouts.reconcile_after_minutes', 2));
        $payouts = Payout::needsCheck($olderThan)->orderBy('id')->limit((int) $this->option('limit'))->get();

        if ($payouts->isEmpty()) {
            $this->info('Nothing to check.');
            return 0;
        }

        $changed = 0;
        $errors = 0;
        foreach ($payouts as $payout) {
            try {
                if ($manager->reconcile($payout)) {
                    $changed++;
                    $this->line(sprintf('#%d %s → %s', $payout->id, $payout->reference, $payout->status));
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->error(sprintf('#%d %s: %s', $payout->id, $payout->reference, $e->getMessage()));
            }
        }

        $this->info(sprintf('Checked %d, updated %d, errors %d.', $payouts->count(), $changed, $errors));

        return $errors ? 1 : 0;
    }
}
