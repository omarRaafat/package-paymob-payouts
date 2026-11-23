<?php

namespace OmarRaafat\PaymobPayouts;

use OmarRaafat\PaymobPayouts\Exceptions\ValidationException;

/**
 * Credentials and connection settings for one Paymob Payouts account.
 *
 * Paymob gives you four credentials for Payouts (separate from Accept / online payments):
 * client id, client secret, username and password. Keep them out of source control.
 */
final class Config
{
    public const STAGING = 'https://stagingpayouts.paymobsolutions.com';
    public const PRODUCTION = 'https://payouts.paymobsolutions.com';

    /** @var string */
    private $baseUrl;
    /** @var string */
    private $clientId;
    /** @var string */
    private $clientSecret;
    /** @var string */
    private $username;
    /** @var string */
    private $password;
    /** @var float */
    private $timeout;
    /** @var float */
    private $connectTimeout;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $username,
        string $password,
        string $baseUrl = self::STAGING,
        float $timeout = 60.0,
        float $connectTimeout = 10.0
    ) {
        foreach (['client id' => $clientId, 'client secret' => $clientSecret, 'username' => $username, 'password' => $password] as $name => $value) {
            if (trim($value) === '') {
                throw new ValidationException("Paymob Payouts {$name} is missing.");
            }
        }

        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->username = $username;
        $this->password = $password;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
        $this->connectTimeout = $connectTimeout;
    }

    /**
     * Build from an array, e.g. a Laravel config file:
     * ['client_id', 'client_secret', 'username', 'password', 'environment' => 'staging'|'production', 'base_url', 'timeout'].
     */
    public static function fromArray(array $config): self
    {
        $baseUrl = $config['base_url'] ?? null;
        if (!$baseUrl) {
            $baseUrl = ($config['environment'] ?? 'staging') === 'production' ? self::PRODUCTION : self::STAGING;
        }

        return new self(
            (string) ($config['client_id'] ?? ''),
            (string) ($config['client_secret'] ?? ''),
            (string) ($config['username'] ?? ''),
            (string) ($config['password'] ?? ''),
            (string) $baseUrl,
            (float) ($config['timeout'] ?? 60),
            (float) ($config['connect_timeout'] ?? 10)
        );
    }

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function clientSecret(): string
    {
        return $this->clientSecret;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    public function connectTimeout(): float
    {
        return $this->connectTimeout;
    }

    /** Cache key for this account's token (several accounts can share one store). */
    public function tokenKey(): string
    {
        return 'paymob_payouts_token_' . substr(sha1($this->baseUrl . '|' . $this->clientId . '|' . $this->username), 0, 16);
    }
}
