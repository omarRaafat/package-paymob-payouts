<?php

namespace OmarRaafat\PaymobPayouts;

/**
 * Paymob's answer to a payout (or to an inquiry about one).
 */
final class DisbursementResult
{
    /** @var string */
    private $status;
    /** @var string|null */
    private $transactionId;
    /** @var string|null */
    private $clientReferenceId;
    /** @var string|null */
    private $paymobStatus;
    /** @var string|null */
    private $statusCode;
    /** @var string|null */
    private $statusDescription;
    /** @var int */
    private $httpStatus;
    /** @var array */
    private $raw;

    public function __construct(
        string $status,
        ?string $transactionId,
        ?string $clientReferenceId,
        ?string $paymobStatus,
        ?string $statusCode,
        ?string $statusDescription,
        int $httpStatus,
        array $raw
    ) {
        $this->status = $status;
        $this->transactionId = $transactionId;
        $this->clientReferenceId = $clientReferenceId;
        $this->paymobStatus = $paymobStatus;
        $this->statusCode = $statusCode;
        $this->statusDescription = $statusDescription;
        $this->httpStatus = $httpStatus;
        $this->raw = $raw;
    }

    /** From a Paymob disburse / inquiry response body. */
    public static function fromResponse(array $body, int $httpStatus, ?string $clientReferenceId = null): self
    {
        // Some answers wrap the transaction (e.g. inquiry lists); accept both shapes.
        $data = isset($body['transaction_id']) || isset($body['disbursement_status']) ? $body : ($body['data'] ?? $body);
        $data = is_array($data) ? $data : [];

        $paymobStatus = self::str($data['disbursement_status'] ?? $data['status'] ?? null);
        $description = self::str($data['status_description'] ?? $body['status_description'] ?? $body['message'] ?? $body['detail'] ?? $body['error_description'] ?? null);

        return new self(
            Status::fromPaymob($paymobStatus, $httpStatus),
            self::str($data['transaction_id'] ?? $data['id'] ?? null),
            self::str($data['client_reference_id'] ?? null) ?? $clientReferenceId,
            $paymobStatus,
            self::str($data['status_code'] ?? $body['status_code'] ?? null),
            $description,
            $httpStatus,
            $body
        );
    }

    /** No answer at all (timeout / connection error): the money may or may not have left. */
    public static function unknown(?string $clientReferenceId, string $reason): self
    {
        return new self(Status::UNKNOWN, null, $clientReferenceId, null, null, $reason, 0, []);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isSuccessful(): bool
    {
        return $this->status === Status::SUCCESSFUL;
    }

    public function isPending(): bool
    {
        return $this->status === Status::PENDING;
    }

    public function isFailed(): bool
    {
        return $this->status === Status::FAILED;
    }

    /** Timed out / unclear: check with an inquiry before doing anything else. */
    public function isUnknown(): bool
    {
        return $this->status === Status::UNKNOWN;
    }

    public function isFinal(): bool
    {
        return Status::isFinal($this->status);
    }

    public function transactionId(): ?string
    {
        return $this->transactionId;
    }

    public function clientReferenceId(): ?string
    {
        return $this->clientReferenceId;
    }

    /** Paymob's own status word (disbursement_status). */
    public function paymobStatus(): ?string
    {
        return $this->paymobStatus;
    }

    public function statusCode(): ?string
    {
        return $this->statusCode;
    }

    public function statusDescription(): ?string
    {
        return $this->statusDescription;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /** Paymob's full response body. */
    public function raw(): array
    {
        return $this->raw;
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'transaction_id' => $this->transactionId,
            'client_reference_id' => $this->clientReferenceId,
            'paymob_status' => $this->paymobStatus,
            'status_code' => $this->statusCode,
            'status_description' => $this->statusDescription,
            'http_status' => $this->httpStatus,
        ];
    }

    private static function str($value): ?string
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        return (string) $value;
    }
}
