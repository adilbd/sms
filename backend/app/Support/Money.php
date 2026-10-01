<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Bangladeshi Taka (BDT) arithmetic in integer paisa (1 taka = 100 paisa), so money is
 * never added, split or compared as a float. Stateless, like Gpa: amounts enter as
 * decimal strings ("1500.50") or numbers, are held as ints, and leave as `decimal:2`
 * strings ("1500.50"), the shape the API sends.
 */
class Money
{
    /** A non-negative amount with up to 2 decimals; `\z` (not `$`) so a trailing newline fails. */
    public const MONEY_PATTERN = '/^\d+(\.\d{1,2})?\z/';

    /**
     * "1500.5" -> 150050. A string is read digit by digit (no float round trip); a third
     * decimal rounds half up, so "0.005" is 1 paisa.
     */
    public static function toPaisa(string|int|float $amount): int
    {
        if (is_int($amount)) {
            return $amount * 100;
        }

        if (is_float($amount)) {
            // Via the shortest string that reads back to the same float, never * 100.
            $amount = rtrim(rtrim(number_format($amount, 8, '.', ''), '0'), '.');
        }

        $amount = trim($amount);

        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $amount, $m)) {
            throw new InvalidArgumentException("Not a decimal amount: {$amount}");
        }

        $fraction = str_pad($m[3] ?? '', 3, '0');
        $paisa = (int) $m[2] * 100 + (int) substr($fraction, 0, 2);

        if ((int) $fraction[2] >= 5) {
            $paisa++;
        }

        return $m[1] === '-' ? -$paisa : $paisa;
    }

    /**
     * 150050 -> "1500.50".
     */
    public static function fromPaisa(int $paisa): string
    {
        $sign = $paisa < 0 ? '-' : '';
        $paisa = abs($paisa);

        return $sign.intdiv($paisa, 100).'.'.str_pad((string) ($paisa % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * $percent of $paisa, rounded half up to the paisa: 33.33% of 100000 (1000.00 taka)
     * is 33330. $percent is a decimal such as "33.33" or 50.
     */
    public static function percentOf(int $paisa, string|int|float $percent): int
    {
        // Hundredths of a percent, so 33.33 is 3333 and the division below is exact.
        $hundredths = self::toPaisa($percent);

        return intdiv($paisa * $hundredths + 5000, 10000);
    }

    /**
     * An amount for people: "1500.5" -> "৳১,৫০০.৫০" (Bangla digits) or "৳1,500.50".
     */
    public static function display(string|int|float $amount, bool $bangla = true): string
    {
        $paisa = self::toPaisa($amount);
        $text = ($paisa < 0 ? '-' : '').number_format(intdiv(abs($paisa), 100)).'.'.str_pad((string) (abs($paisa) % 100), 2, '0', STR_PAD_LEFT);

        return '৳'.($bangla ? BanglaNumber::format($text) : $text);
    }
}
