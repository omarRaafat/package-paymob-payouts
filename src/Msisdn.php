<?php

namespace OmarRaafat\PaymobPayouts;

/**
 * Egyptian mobile numbers in the local 11-digit form Paymob expects (01XXXXXXXXX).
 */
final class Msisdn
{
    /** 010 Vodafone, 011 Etisalat, 012 Orange, 015 WE. */
    public const PATTERN = '/^01[0125][0-9]{8}$/';

    /**
     * "+20 100 123 4567", "00201001234567", "201001234567", "1001234567" → "01001234567".
     * Arabic-Indic digits are converted too.
     */
    public static function normalize(string $msisdn): string
    {
        $msisdn = strtr($msisdn, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $digits = preg_replace('/\D+/', '', $msisdn);

        if (strpos($digits, '0020') === 0) {
            $digits = substr($digits, 4);
        } elseif (strpos($digits, '20') === 0 && strlen($digits) === 12) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) === 10 && $digits[0] === '1') {
            $digits = '0' . $digits;
        }

        return $digits;
    }

    public static function isValid(string $msisdn): bool
    {
        return (bool) preg_match(self::PATTERN, self::normalize($msisdn));
    }

    /** 010****4567, for logs and screens. */
    public static function mask(string $msisdn): string
    {
        $n = self::normalize($msisdn);

        return strlen($n) >= 7 ? substr($n, 0, 3) . str_repeat('*', strlen($n) - 7) . substr($n, -4) : '****';
    }
}
