<?php

namespace OmarRaafat\PaymobPayouts;

/**
 * Outcome of a payout.
 *
 * UNKNOWN matters: when Paymob times out or answers with a server error, the money may or
 * may not have left. Never treat that as FAILED and send again; check it first with
 * PaymobPayouts::inquireByReference() (or the Laravel `paymob-payouts:reconcile` command).
 */
final class Status
{
    public const SUCCESSFUL = 'successful';
    public const PENDING = 'pending';
    public const FAILED = 'failed';
    public const UNKNOWN = 'unknown';

    /** Paymob status words that mean the money was sent. */
    private const SUCCESS_WORDS = ['successful', 'success', 'disbursed', 'completed', 'approved'];
    private const PENDING_WORDS = ['pending', 'processing', 'in_progress', 'in progress', 'initiated'];
    private const FAILED_WORDS = ['failed', 'failure', 'rejected', 'declined', 'cancelled', 'canceled', 'error'];

    /** Map Paymob's `disbursement_status` (and HTTP status) to one of the four outcomes. */
    public static function fromPaymob(?string $disbursementStatus, int $httpStatus = 200): string
    {
        $word = strtolower(trim((string) $disbursementStatus));

        if (in_array($word, self::SUCCESS_WORDS, true)) {
            return self::SUCCESSFUL;
        }
        if (in_array($word, self::PENDING_WORDS, true)) {
            return self::PENDING;
        }
        if (in_array($word, self::FAILED_WORDS, true)) {
            return self::FAILED;
        }

        // No recognisable status word: a 4xx is a refusal (nothing sent); anything else is unclear.
        return $httpStatus >= 400 && $httpStatus < 500 ? self::FAILED : self::UNKNOWN;
    }

    /** Final outcomes; PENDING and UNKNOWN still need checking. */
    public static function isFinal(string $status): bool
    {
        return in_array($status, [self::SUCCESSFUL, self::FAILED], true);
    }
}
