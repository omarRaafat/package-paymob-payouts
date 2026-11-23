<?php

namespace OmarRaafat\PaymobPayouts;

/**
 * Where the money goes. Values are the `issuer` codes Paymob expects.
 */
final class Issuer
{
    public const VODAFONE = 'vodafone';
    public const ETISALAT = 'etisalat';
    public const ORANGE = 'orange';
    public const BANK_WALLET = 'bank_wallet';
    public const BANK_CARD = 'bank_card';
    public const INSTANT_BANK = 'instant_bank';
    public const POST = 'post';

    /** Mobile wallets: paid to a phone number (msisdn). */
    public const WALLETS = [self::VODAFONE, self::ETISALAT, self::ORANGE, self::BANK_WALLET];

    /** Fields Paymob needs for each issuer, besides `amount` and `issuer`. */
    public const REQUIRED_FIELDS = [
        self::VODAFONE => ['msisdn'],
        self::ETISALAT => ['msisdn'],
        self::ORANGE => ['msisdn', 'full_name'],
        self::BANK_WALLET => ['msisdn', 'full_name'],
        self::BANK_CARD => ['bank_card_number', 'bank_code', 'full_name', 'bank_transaction_type'],
        self::INSTANT_BANK => ['bank_card_number', 'bank_code', 'full_name'],
        self::POST => ['national_id', 'full_name'],
    ];

    public const LABELS = [
        self::VODAFONE => 'Vodafone Cash',
        self::ETISALAT => 'Etisalat Cash (e& money)',
        self::ORANGE => 'Orange Cash',
        self::BANK_WALLET => 'Bank wallet',
        self::BANK_CARD => 'Bank card',
        self::INSTANT_BANK => 'Instant bank transfer',
        self::POST => 'Egypt Post',
    ];

    /** `bank_transaction_type` values for bank_card payouts. */
    public const BANK_TRANSACTION_TYPES = ['salary', 'credit_card', 'prepaid_card', 'cash_transfer'];

    public static function all(): array
    {
        return array_keys(self::REQUIRED_FIELDS);
    }

    public static function isValid(string $issuer): bool
    {
        return isset(self::REQUIRED_FIELDS[$issuer]);
    }

    public static function isWallet(string $issuer): bool
    {
        return in_array($issuer, self::WALLETS, true);
    }

    public static function label(string $issuer): string
    {
        return self::LABELS[$issuer] ?? ucfirst(str_replace('_', ' ', $issuer));
    }

    /**
     * Best guess of the wallet issuer from an Egyptian mobile number prefix
     * (010 Vodafone, 011 Etisalat, 012 Orange). 015 (WE) has no Paymob wallet issuer.
     * Numbers can be ported between networks, so let the user confirm.
     */
    public static function guessFromMsisdn(string $msisdn): ?string
    {
        $prefixes = ['010' => self::VODAFONE, '011' => self::ETISALAT, '012' => self::ORANGE];

        return $prefixes[substr(Msisdn::normalize($msisdn), 0, 3)] ?? null;
    }
}
