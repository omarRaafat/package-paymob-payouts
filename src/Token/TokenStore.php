<?php

namespace OmarRaafat\PaymobPayouts\Token;

/**
 * Where access tokens are kept between requests. Paymob tokens last 60 minutes; reusing them
 * avoids a login call before every payout. Use a shared store (cache, file) in production.
 */
interface TokenStore
{
    /** ['access_token' => ..., 'refresh_token' => ..., 'expires_at' => unix time] or null. */
    public function get(string $key): ?array;

    public function put(string $key, array $token, int $ttlSeconds): void;

    public function forget(string $key): void;
}
