<?php

return [

    /*
    | Paymob Payouts credentials (from Paymob; separate from Accept / online payments).
    | Keep them in .env, never in code.
    */
    'client_id' => env('PAYMOB_PAYOUTS_CLIENT_ID'),
    'client_secret' => env('PAYMOB_PAYOUTS_CLIENT_SECRET'),
    'username' => env('PAYMOB_PAYOUTS_USERNAME'),
    'password' => env('PAYMOB_PAYOUTS_PASSWORD'),

    // 'staging' (test money) or 'production' (real money).
    'environment' => env('PAYMOB_PAYOUTS_ENV', 'staging'),

    // Override the host if Paymob gives you a different one.
    'base_url' => env('PAYMOB_PAYOUTS_BASE_URL'),

    // Seconds to wait for Paymob. A payout that times out is recorded as "unknown" and checked later.
    'timeout' => (int) env('PAYMOB_PAYOUTS_TIMEOUT', 60),
    'connect_timeout' => (int) env('PAYMOB_PAYOUTS_CONNECT_TIMEOUT', 10),

    // Cache store for the access token (null = default store). Use a shared store (redis, database, file).
    'cache_store' => env('PAYMOB_PAYOUTS_CACHE_STORE'),

    // Log channel for payout activity (null = default channel).
    'log_channel' => env('PAYMOB_PAYOUTS_LOG_CHANNEL'),

    // Table that records every payout (PayoutManager).
    'table' => 'paymob_payouts',

    // Reconcile: payouts still pending / unknown after this many minutes are checked with Paymob.
    'reconcile_after_minutes' => 2,
];
