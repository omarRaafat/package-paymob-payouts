<?php

namespace OmarRaafat\PaymobPayouts\Exceptions;

/** Paymob answered an inquiry / balance call with an error, or could not be reached. */
class ApiException extends PaymobPayoutsException
{
    /** @var int */
    private $httpStatus;
    /** @var array */
    private $body;

    public function __construct(string $message, int $httpStatus = 0, array $body = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->httpStatus = $httpStatus;
        $this->body = $body;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function body(): array
    {
        return $this->body;
    }
}
