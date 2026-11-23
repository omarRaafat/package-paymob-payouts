<?php

namespace OmarRaafat\PaymobPayouts\Token;

/** Keeps the token for the life of the PHP process only (default; fine for scripts and tests). */
final class InMemoryTokenStore implements TokenStore
{
    /** @var array<string, array> */
    private $tokens = [];

    public function get(string $key): ?array
    {
        $token = $this->tokens[$key] ?? null;

        return $token && ($token['expires_at'] ?? 0) > time() ? $token : null;
    }

    public function put(string $key, array $token, int $ttlSeconds): void
    {
        $this->tokens[$key] = $token;
    }

    public function forget(string $key): void
    {
        unset($this->tokens[$key]);
    }
}
