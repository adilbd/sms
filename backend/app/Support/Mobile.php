<?php

namespace App\Support;

/**
 * Bangladeshi mobile numbers are stored as 01XXXXXXXXX (11 digits, the second operator
 * digit 3-9). Stateless helper, used by the student/guardian requests and by login.
 */
class Mobile
{
    public const PATTERN = '/^01[3-9][0-9]{8}$/';

    /**
     * Strips spaces/dashes and a +88 / 88 country prefix. Returns the input untouched
     * (apart from trimming) when it is not shaped like a mobile number, so validation
     * can reject it and login can fall back to other identifiers.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $compact = preg_replace('/[\s\-()]/', '', trim($value)) ?? '';

        if (preg_match('/^\+?88(01[0-9]{9})$/', $compact, $matches)) {
            return $matches[1];
        }

        return $compact === '' ? trim($value) : $compact;
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match(self::PATTERN, $value);
    }
}
