<?php

namespace App\Support;

/**
 * Writes the digits of a number in Bangla (০–৯). Stateless, like Seo: "3.64" becomes
 * "৩.৬৪" and any other character (a point, a slash, a minus sign, a letter) is kept.
 */
class BanglaNumber
{
    private const DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    public static function format(int|float|string|null $value): string
    {
        if ($value === null) {
            return '';
        }

        return strtr((string) $value, array_combine(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], self::DIGITS));
    }
}
