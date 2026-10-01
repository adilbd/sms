<?php

namespace App\Support;

/**
 * An exam's display name with its academic year, without printing the year twice when the
 * name already carries it ("অর্ধবার্ষিক পরীক্ষা ২০২৬"). ASCII and Bangla digits count as the
 * same, so "Half Yearly 2026" and "অর্ধবার্ষিক ২০২৬" both already contain 2026.
 * Mirrored by resources/js/admin/utils/examTitle.js. Stateless, like BanglaNumber.
 */
class ExamTitle
{
    public static function withYear(?string $name, int|string|null $year, bool $bangla = false): string
    {
        $name = trim((string) $name);
        $year = (string) $year;

        if ($year === '') {
            return $name;
        }

        if ($name === '') {
            return $bangla ? BanglaNumber::format($year) : $year;
        }

        $ascii = strtr($name, array_combine(['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'], ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9']));

        if (preg_match('/(?<![0-9])'.preg_quote($year, '/').'(?![0-9])/', $ascii) === 1) {
            return $name;
        }

        return $name.' '.($bangla ? BanglaNumber::format($year) : $year);
    }
}
