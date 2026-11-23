<?php

namespace OmarRaafat\PaymobPayouts\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use OmarRaafat\PaymobPayouts\Config;
use OmarRaafat\PaymobPayouts\PaymobPayouts;
use OmarRaafat\PaymobPayouts\Token\InMemoryTokenStore;
use OmarRaafat\PaymobPayouts\Token\TokenStore;

/** A PaymobPayouts client whose HTTP answers are scripted, and whose requests are recorded. */
final class FakePaymob
{
    /** @var MockHandler */
    public $mock;
    /** @var array */
    public $history = [];
    /** @var PaymobPayouts */
    public $client;
    /** @var array */
    public $logs = [];

    public function __construct(?TokenStore $tokens = null)
    {
        $this->mock = new MockHandler();
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));

        $this->client = new PaymobPayouts(
            new Config('cid', 'csecret', 'user', 'pass', Config::STAGING),
            $tokens ?: new InMemoryTokenStore(),
            new Client(['handler' => $stack]),
            function ($level, $message, $context) {
                $this->logs[] = [$level, $message, $context];
            }
        );
    }

    public static function json(int $status, array $body): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($body));
    }

    public function queueToken(string $access = 'access-1', string $refresh = 'refresh-1', ?int $expiresIn = 3600): self
    {
        $body = ['access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'Bearer'];
        if ($expiresIn !== null) {
            $body['expires_in'] = $expiresIn;
        }
        $this->mock->append(self::json(200, $body));

        return $this;
    }

    public function queue(...$responses): self
    {
        foreach ($responses as $response) {
            $this->mock->append($response);
        }

        return $this;
    }

    /** The n-th recorded request. */
    public function request(int $i)
    {
        return $this->history[$i]['request'];
    }

    public function jsonBody(int $i): array
    {
        return json_decode((string) $this->request($i)->getBody(), true) ?: [];
    }

    public function formBody(int $i): array
    {
        parse_str((string) $this->request($i)->getBody(), $form);

        return $form;
    }
}
