<?php

namespace OmarRaafat\PaymobPayouts\Laravel;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use OmarRaafat\PaymobPayouts\Config;
use OmarRaafat\PaymobPayouts\Laravel\Console\BalanceCommand;
use OmarRaafat\PaymobPayouts\Laravel\Console\ReconcileCommand;
use OmarRaafat\PaymobPayouts\PaymobPayouts;

class PaymobPayoutsServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/paymob-payouts.php', 'paymob-payouts');

        $this->app->singleton(PaymobPayouts::class, function ($app) {
            $config = $app['config']->get('paymob-payouts');
            $channel = $config['log_channel'] ?? null;

            return new PaymobPayouts(
                Config::fromArray($config),
                new LaravelCacheTokenStore($app['cache']->store($config['cache_store'] ?? null)),
                null,
                function (string $level, string $message, array $context) use ($channel) {
                    ($channel ? Log::channel($channel) : Log::getFacadeRoot())->log($level, $message, $context);
                }
            );
        });

        $this->app->singleton(PayoutManager::class, function ($app) {
            return new PayoutManager($app->make(PaymobPayouts::class));
        });

        $this->app->alias(PaymobPayouts::class, 'paymob-payouts');
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/paymob-payouts.php' => config_path('paymob-payouts.php'),
            ], 'paymob-payouts-config');

            $this->publishes([
                __DIR__ . '/../../database/migrations/' => database_path('migrations'),
            ], 'paymob-payouts-migrations');

            $this->commands([ReconcileCommand::class, BalanceCommand::class]);
        }
    }
}
