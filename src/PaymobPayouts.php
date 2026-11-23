<?php

namespace OmarRaafat\PaymobPayouts;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use OmarRaafat\PaymobPayouts\Exceptions\ApiException;
use OmarRaafat\PaymobPayouts\Exceptions\AuthenticationException;
use OmarRaafat\PaymobPayouts\Token\InMemoryTokenStore;
use OmarRaafat\PaymobPayouts\Token\TokenStore;

/**
 * Paymob Payouts API client.
 *
 *   $paymob = new PaymobPayouts(Config::fromArray([...]), new FileTokenStore('/var/app/paymob'));
 *   $result = $paymob->disburse(DisbursementRequest::vodafone('01001234567', 250));
 *
 *   if ($result->isSuccessful()) { ... }        // money sent
 *   elseif ($result->isFailed()) { ... }        // refused, nothing sent: safe to fix and retry
 *   else { ... }                                // pending / unknown: check later, do NOT resend
 *
 * Endpoints follow Paymob's official Payouts Postman collection
 * (github.com/PaymobAccept/API-Postman-Collections, "Payouts API").
 */
class PaymobPayouts
{
    public const TOKEN_PATH = '/api/secure/o/token/';
    public const DISBURSE_PATH = '/api/secure/disburse/';
    public const INQUIRE_PATH = '/api/secure/transaction/inquire/';
    public const INQUIRE_BY_REFERENCE_PATH = '/api/secure/transaction/inquire/by-reference/';
    public const BUDGET_PATH = '/api/secure/budget/inquire/';

    /** Paymob tokens live 60 minutes; renew a minute early. */
    private const TOKEN_LIFETIME = 3600;
    private const TOKEN_MARGIN = 60;

    /** @var Config */
    private $config;
    /** @var TokenStore */
    private $tokens;
    /** @var ClientInterface */
    private $http;
    /** @var callable|null fn(string $level, string $message, array $context) */
    private $logger;

    public function __construct(Config $config, ?TokenStore $tokens = null, ?ClientInterface $http = null, ?callable $logger = null)
    {
        $this->config = $config;
        $this->tokens = $tokens ?: new InMemoryTokenStore();
        $this->http = $http ?: new Client([
            'timeout' => $config->timeout(),
            'connect_timeout' => $config->connectTimeout(),
        ]);
        $this->logger = $logger;
    }

    /**
     * Send money. Validates first (ValidationException, nothing sent), then calls Paymob once.
     *
     * Never throws for a payout outcome: a refusal is a FAILED result, and a timeout or server
     * error is an UNKNOWN result (the money may have left; check it with inquireByReference()
     * or transactionStatus() before sending again).
     *
     * @throws Exceptions\ValidationException|AuthenticationException
     */
    public function disburse(DisbursementRequest $request): DisbursementResult
    {
        $request->validate();
        $reference = $request->clientReferenceId();

        $this->log('info', 'Paymob payout: sending', $request->toSafeArray());

        try {
            $response = $this->authorized('POST', self::DISBURSE_PATH, ['json' => $request->toArray()]);
        } catch (AuthenticationException $e) {
            throw $e;
        } catch (ApiException $e) {
            // Connection dropped / timed out: Paymob may have processed it.
            $this->log('warning', 'Paymob payout: no answer, outcome unknown', ['client_reference_id' => $reference, 'error' => $e->getMessage()]);

            return DisbursementResult::unknown($reference, 'No answer from Paymob: ' . $e->getMessage());
        }

        $result = DisbursementResult::fromResponse($this->decode($response), $response->getStatusCode(), $reference);

        $this->log($result->isSuccessful() ? 'info' : 'warning', 'Paymob payout: ' . $result->status(), $result->toArray());

        return $result;
    }

    /**
     * Raw inquiry for Paymob transaction ids.
     * $bankTransactions = true for bank_card / instant_bank payouts.
     *
     * @param string[] $transactionIds
     * @throws ApiException|AuthenticationException
     */
    public function inquire(array $transactionIds, bool $bankTransactions = false): array
    {
        $body = ['transactions_ids_list' => array_values(array_map('strval', $transactionIds))];
        if ($bankTransactions) {
            $body['bank_transactions'] = true;
        }

        return $this->decodeOrFail($this->authorized('GET', self::INQUIRE_PATH, ['json' => $body]), 'Transaction inquiry');
    }

    /**
     * Raw inquiry by your client_reference_id values (exact match, up to 140 characters each).
     * Use it when a payout timed out and you have no Paymob transaction id.
     *
     * Note: Paymob's collection marks this path as inferred; confirm it with Paymob for your account.
     *
     * @param string[] $references
     * @throws ApiException|AuthenticationException
     */
    public function inquireByReference(array $references, bool $bankTransactions = false): array
    {
        $body = [
            'references_list' => array_values(array_map('strval', $references)),
            'bank_transactions' => $bankTransactions,
        ];

        return $this->decodeOrFail($this->authorized('GET', self::INQUIRE_BY_REFERENCE_PATH, ['json' => $body]), 'Reference inquiry');
    }

    /**
     * Current state of one payout, looked up by Paymob transaction id or, failing that,
     * by your client reference id. Null when Paymob does not know it.
     *
     * @throws ApiException|AuthenticationException
     */
    public function transactionStatus(?string $transactionId, ?string $clientReferenceId = null, bool $bankTransactions = false): ?DisbursementResult
    {
        if ($transactionId) {
            $found = $this->pick(self::transactionsIn($this->inquire([$transactionId], $bankTransactions)), 'transaction_id', $transactionId);
            if ($found) {
                return DisbursementResult::fromResponse($found, 200, $clientReferenceId);
            }
        }

        if ($clientReferenceId) {
            $found = $this->pick(self::transactionsIn($this->inquireByReference([$clientReferenceId], $bankTransactions)), 'client_reference_id', $clientReferenceId);
            if ($found) {
                return DisbursementResult::fromResponse($found, 200, $clientReferenceId);
            }
        }

        return null;
    }

    /**
     * Raw balance answer of the Payouts account (money available to send).
     *
     * @throws ApiException|AuthenticationException
     */
    public function budget(): array
    {
        return $this->decodeOrFail($this->authorized('GET', self::BUDGET_PATH), 'Budget inquiry');
    }

    /** Available balance as a number, or null if the answer has no recognisable amount. */
    public function balance(): ?float
    {
        $budget = $this->budget();
        foreach (['current_budget', 'current_balance', 'budget', 'balance'] as $key) {
            $value = $budget[$key] ?? ($budget['data'][$key] ?? null);
            if (is_numeric($value)) {
                return (float) $value;
            }
            // e.g. "Your current budget is 1500.00 LE"
            if (is_string($value) && preg_match('/-?\d[\d,]*(?:\.\d+)?/', $value, $m)) {
                return (float) str_replace(',', '', $m[0]);
            }
        }

        return null;
    }

    /** A valid access token (cached; renewed with the refresh token, or by logging in again). */
    public function accessToken(): string
    {
        $key = $this->config->tokenKey();
        $token = $this->tokens->get($key);

        if ($token && ($token['expires_at'] ?? 0) - self::TOKEN_MARGIN > time() && !empty($token['access_token'])) {
            return $token['access_token'];
        }

        if ($token && !empty($token['refresh_token'])) {
            try {
                return $this->requestToken([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $token['refresh_token'],
                ]);
            } catch (AuthenticationException $e) {
                // Refresh token expired: fall through to a fresh login.
            }
        }

        return $this->requestToken([
            'grant_type' => 'password',
            'username' => $this->config->username(),
            'password' => $this->config->password(),
        ]);
    }

    /** Drop the cached token (e.g. after rotating credentials). */
    public function forgetToken(): void
    {
        $this->tokens->forget($this->config->tokenKey());
    }

    /**
     * Normalises the possible inquiry answer shapes into a list of transaction arrays.
     */
    public static function transactionsIn(array $body): array
    {
        foreach (['transactions', 'results', 'data'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                $body = $body[$key];
                break;
            }
        }

        if (isset($body['transaction_id']) || isset($body['disbursement_status'])) {
            return [$body];
        }

        return array_values(array_filter($body, 'is_array'));
    }

    /** Call an endpoint with the bearer token; on 401 log in again once and retry. */
    private function authorized(string $method, string $path, array $options = []): ResponseInterface
    {
        $response = $this->send($method, $path, $options + ['headers' => ['Authorization' => 'Bearer ' . $this->accessToken()]]);

        if ($response->getStatusCode() === 401) {
            // Token revoked or expired early. Paymob refused the call, so retrying cannot pay twice.
            $this->forgetToken();
            $options['headers'] = ['Authorization' => 'Bearer ' . $this->accessToken()];
            $response = $this->send($method, $path, $options);
        }

        return $response;
    }

    private function requestToken(array $grant): string
    {
        $form = $grant + [
            'client_id' => $this->config->clientId(),
            'client_secret' => $this->config->clientSecret(),
        ];

        try {
            $response = $this->send('POST', self::TOKEN_PATH, ['form_params' => $form], false);
        } catch (ApiException $e) {
            throw new ApiException('Could not reach Paymob to log in: ' . $e->getMessage(), 0, [], $e);
        }

        $body = $this->decode($response);
        if ($response->getStatusCode() >= 400 || empty($body['access_token'])) {
            $this->log('error', 'Paymob payout: login refused', ['http_status' => $response->getStatusCode(), 'error' => $body['error'] ?? null]);
            throw new AuthenticationException('Paymob Payouts refused the credentials (' . ($body['error_description'] ?? $body['error'] ?? 'HTTP ' . $response->getStatusCode()) . ').');
        }

        $ttl = (int) ($body['expires_in'] ?? self::TOKEN_LIFETIME) ?: self::TOKEN_LIFETIME;
        $this->tokens->put($this->config->tokenKey(), [
            'access_token' => $body['access_token'],
            'refresh_token' => $body['refresh_token'] ?? null,
            'expires_at' => time() + $ttl,
        ], $ttl + 86400);

        return $body['access_token'];
    }

    /** @throws ApiException when Paymob cannot be reached (no HTTP answer). */
    private function send(string $method, string $path, array $options, bool $json = true): ResponseInterface
    {
        $options['http_errors'] = false;
        $options['headers'] = ($options['headers'] ?? []) + ['Accept' => 'application/json'];

        try {
            return $this->http->request($method, $this->config->baseUrl() . $path, $options);
        } catch (GuzzleException $e) {
            throw new ApiException($e->getMessage(), 0, [], $e instanceof \Throwable ? $e : null);
        }
    }

    private function decode(ResponseInterface $response): array
    {
        $raw = (string) $response->getBody();
        $data = json_decode($raw, true);

        return is_array($data) ? $data : ($raw === '' ? [] : ['raw' => mb_substr($raw, 0, 2000)]);
    }

    private function decodeOrFail(ResponseInterface $response, string $what): array
    {
        $body = $this->decode($response);
        if ($response->getStatusCode() >= 400) {
            throw new ApiException($what . ' failed (HTTP ' . $response->getStatusCode() . ').', $response->getStatusCode(), $body);
        }

        return $body;
    }

    private function pick(array $transactions, string $field, string $value): ?array
    {
        foreach ($transactions as $transaction) {
            if ((string) ($transaction[$field] ?? '') === $value) {
                return $transaction;
            }
        }

        return count($transactions) === 1 ? $transactions[0] : null;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            ($this->logger)($level, $message, $context);
        }
    }
}
