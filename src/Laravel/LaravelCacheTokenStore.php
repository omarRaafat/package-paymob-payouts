<?php

namespace OmarRaafat\PaymobPayouts\Laravel;

use Illuminate\Contracts\Cache\Repository;
use OmarRaafat\PaymobPayouts\Token\TokenStore;

/** Keeps the Paymob token in Laravel's cache, shared by every request and queue worker. */
final class LaravelCacheTokenStore implements TokenStore
{
    /** @var Repository */
    private $cache;

    public function __construct(Repository $cache)
    {
        $this->cache = $cache;
    }

    public function get(string $key): ?array
    {
        $token = $this->cache->get($key);

        return is_array($token) ? $token : null;
    }

    public function put(string $key, array $token, int $ttlSeconds): void
    {
        $this->cache->put($key, $token, $ttlSeconds);
    }

    public function forget(string $key): void
    {
        $this->cache->forget($key);
    }
}
