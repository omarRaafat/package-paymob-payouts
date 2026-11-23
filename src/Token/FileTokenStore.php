<?php

namespace OmarRaafat\PaymobPayouts\Token;

/**
 * Keeps tokens in a private file, so separate PHP requests share one login.
 * Put the directory outside the web root.
 */
final class FileTokenStore implements TokenStore
{
    /** @var string */
    private $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    public function get(string $key): ?array
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }

        $token = json_decode((string) @file_get_contents($path), true);

        return is_array($token) && ($token['expires_at'] ?? 0) > time() ? $token : null;
    }

    public function put(string $key, array $token, int $ttlSeconds): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0700, true);
        }

        $path = $this->path($key);
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($tmp, json_encode($token), LOCK_EX) !== false) {
            @chmod($tmp, 0600);
            @rename($tmp, $path);
        }
    }

    public function forget(string $key): void
    {
        @unlink($this->path($key));
    }

    private function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9_\-]/', '_', $key) . '.json';
    }
}
