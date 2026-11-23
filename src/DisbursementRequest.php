<?php

namespace OmarRaafat\PaymobPayouts;

use OmarRaafat\PaymobPayouts\Exceptions\ValidationException;

/**
 * One payout to send. Build it with a named constructor:
 *
 *   DisbursementRequest::wallet(Issuer::VODAFONE, '01001234567', 250.00);
 *   DisbursementRequest::wallet(Issuer::ORANGE, '01201234567', 100, 'Ahmed Ali');
 *   DisbursementRequest::bankCard('1111222233334444', 'CIB', 'Ahmed Ali', 1500);
 *   DisbursementRequest::instantBank('EG380019000500000000263180002', 'CIB', 'Ahmed Ali', 1500);
 *   DisbursementRequest::post('29001010000000', 'Ahmed Ali', 300);
 *
 * Every request carries a client_reference_id (a UUID, made for you unless you pass one).
 * Store it before sending: if the call times out you can ask Paymob what happened with it
 * instead of paying twice.
 */
final class DisbursementRequest
{
    /** Paymob's documented minimum for instant bank transfers. */
    public const INSTANT_BANK_MIN_AMOUNT = 112.0;

    /** @var string */
    private $issuer;
    /** @var float */
    private $amount;
    /** @var array<string, string> */
    private $fields;
    /** @var string */
    private $clientReferenceId;
    /** @var bool */
    private $customerBearsFees = false;

    private function __construct(string $issuer, $amount, array $fields)
    {
        $this->issuer = $issuer;
        $this->amount = round((float) $amount, 2);
        $this->fields = array_filter($fields, function ($value) {
            return $value !== null && $value !== '';
        });
        $this->clientReferenceId = Uuid::v4();
    }

    /** Vodafone / Etisalat / Orange cash, or a bank wallet. Orange and bank wallets need the full name. */
    public static function wallet(string $issuer, string $msisdn, $amount, ?string $fullName = null): self
    {
        return new self($issuer, $amount, [
            'msisdn' => Msisdn::normalize($msisdn),
            'full_name' => $fullName !== null ? trim($fullName) : null,
        ]);
    }

    public static function vodafone(string $msisdn, $amount): self
    {
        return self::wallet(Issuer::VODAFONE, $msisdn, $amount);
    }

    public static function etisalat(string $msisdn, $amount): self
    {
        return self::wallet(Issuer::ETISALAT, $msisdn, $amount);
    }

    public static function orange(string $msisdn, $amount, string $fullName): self
    {
        return self::wallet(Issuer::ORANGE, $msisdn, $amount, $fullName);
    }

    public static function bankWallet(string $msisdn, $amount, string $fullName): self
    {
        return self::wallet(Issuer::BANK_WALLET, $msisdn, $amount, $fullName);
    }

    /** Card payout. $transactionType: salary, credit_card, prepaid_card or cash_transfer. */
    public static function bankCard(string $cardNumber, string $bankCode, string $fullName, $amount, string $transactionType = 'cash_transfer', ?string $comment = null): self
    {
        return new self(Issuer::BANK_CARD, $amount, [
            'bank_card_number' => preg_replace('/\s+/', '', $cardNumber),
            'bank_code' => strtoupper(trim($bankCode)),
            'full_name' => trim($fullName),
            'bank_transaction_type' => $transactionType,
            'comment' => $comment,
        ]);
    }

    /** Instant transfer to a bank account number or IBAN. Bank codes differ from the bank_card list. */
    public static function instantBank(string $accountOrIban, string $bankCode, string $fullName, $amount): self
    {
        return new self(Issuer::INSTANT_BANK, $amount, [
            'bank_card_number' => preg_replace('/\s+/', '', $accountOrIban),
            'bank_code' => strtoupper(trim($bankCode)),
            'full_name' => trim($fullName),
        ]);
    }

    /** Cash collected at an Egypt Post branch with the national id. */
    public static function post(string $nationalId, string $fullName, $amount): self
    {
        return new self(Issuer::POST, $amount, [
            'national_id' => preg_replace('/\D+/', '', $nationalId),
            'full_name' => trim($fullName),
        ]);
    }

    /** Your own id for this payout (stored with you, used to look it up later). Must be unique per payout. */
    public function withClientReferenceId(string $clientReferenceId): self
    {
        $clone = clone $this;
        $clone->clientReferenceId = $clientReferenceId;

        return $clone;
    }

    /** true = the receiver pays the transfer fees and VAT (Paymob default: false, you pay). */
    public function withCustomerBearsFees(bool $customerBearsFees = true): self
    {
        $clone = clone $this;
        $clone->customerBearsFees = $customerBearsFees;

        return $clone;
    }

    /** @throws ValidationException with every problem found. */
    public function validate(): void
    {
        $errors = [];

        if (!Issuer::isValid($this->issuer)) {
            $errors['issuer'] = 'Unknown issuer "' . $this->issuer . '". Use one of: ' . implode(', ', Issuer::all()) . '.';
        } else {
            foreach (Issuer::REQUIRED_FIELDS[$this->issuer] as $field) {
                if (!isset($this->fields[$field])) {
                    $errors[$field] = str_replace('_', ' ', $field) . ' is required for ' . Issuer::label($this->issuer) . '.';
                }
            }
        }

        if ($this->amount <= 0) {
            $errors['amount'] = 'Amount must be greater than zero.';
        } elseif ($this->issuer === Issuer::INSTANT_BANK && $this->amount < self::INSTANT_BANK_MIN_AMOUNT) {
            $errors['amount'] = 'Instant bank transfers start at ' . self::INSTANT_BANK_MIN_AMOUNT . ' EGP.';
        }

        if (isset($this->fields['msisdn']) && !Msisdn::isValid($this->fields['msisdn'])) {
            $errors['msisdn'] = 'Mobile number must be an Egyptian number like 01001234567.';
        }

        if (isset($this->fields['national_id']) && !preg_match('/^[23][0-9]{13}$/', $this->fields['national_id'])) {
            $errors['national_id'] = 'National id must be 14 digits.';
        }

        if ($this->issuer === Issuer::BANK_CARD && isset($this->fields['bank_transaction_type'])
            && !in_array($this->fields['bank_transaction_type'], Issuer::BANK_TRANSACTION_TYPES, true)) {
            $errors['bank_transaction_type'] = 'Bank transaction type must be one of: ' . implode(', ', Issuer::BANK_TRANSACTION_TYPES) . '.';
        }

        if (strlen($this->clientReferenceId) > 140) {
            $errors['client_reference_id'] = 'Client reference id is limited to 140 characters.';
        }

        if ($errors) {
            throw new ValidationException('The payout request is not valid: ' . implode(' ', $errors), $errors);
        }
    }

    /** The JSON body sent to Paymob's disburse endpoint. */
    public function toArray(): array
    {
        return array_merge(
            [
                'issuer' => $this->issuer,
                'amount' => number_format($this->amount, 2, '.', ''),
            ],
            $this->fields,
            [
                'client_reference_id' => $this->clientReferenceId,
                // Paymob's inquiry "by reference" searches this field (verified on staging), so the
                // same id is sent here too: a payout that timed out can still be found.
                'reference' => $this->clientReferenceId,
                'customer_bears_fees' => $this->customerBearsFees,
            ]
        );
    }

    public function issuer(): string
    {
        return $this->issuer;
    }

    public function amount(): float
    {
        return $this->amount;
    }

    public function clientReferenceId(): string
    {
        return $this->clientReferenceId;
    }

    public function customerBearsFees(): bool
    {
        return $this->customerBearsFees;
    }

    /** Where the money goes: the phone number, card / account number or national id. */
    public function destination(): ?string
    {
        return $this->fields['msisdn'] ?? $this->fields['bank_card_number'] ?? $this->fields['national_id'] ?? null;
    }

    public function fullName(): ?string
    {
        return $this->fields['full_name'] ?? null;
    }

    /** Same as toArray() with the destination partly hidden, for logs. */
    public function toSafeArray(): array
    {
        $data = $this->toArray();
        foreach (['msisdn', 'bank_card_number', 'national_id'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = $key === 'msisdn' ? Msisdn::mask($data[$key]) : '****' . substr($data[$key], -4);
            }
        }

        return $data;
    }
}
