<?php

namespace OmarRaafat\PaymobPayouts\Exceptions;

/** The request was rejected before anything was sent to Paymob. */
class ValidationException extends PaymobPayoutsException
{
    /** @var array<string, string> */
    private $errors;

    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /** Problems keyed by field name. */
    public function errors(): array
    {
        return $this->errors;
    }
}
