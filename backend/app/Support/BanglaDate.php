<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Dates and times written in Bangla for Blade: Bangla digits, Bangla month names and
 * পূর্বাহ্ন / অপরাহ্ন instead of "am" / "pm" ("১ অক্টোবর ২০২৬, অপরাহ্ন ৩:০৭"). Timestamps
 * are stored in UTC and shown in Asia/Dhaka, the school's local time. Stateless, like
 * BanglaNumber, and mirrored by resources/js/admin/utils/banglaDate.js.
 */
class BanglaDate
{
    public const TIMEZONE = 'Asia/Dhaka';

    public const MONTHS = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];

    public const AM = 'পূর্বাহ্ন';

    public const PM = 'অপরাহ্ন';

    /**
     * "১ অক্টোবর ২০২৬, অপরাহ্ন ৩:০৭". A string without a zone is read as UTC.
     */
    public static function dateTime(CarbonInterface|string|null $value): string
    {
        $at = self::dhaka($value);

        if ($at === null) {
            return '';
        }

        $hour = (int) $at->format('G');

        return self::date($at).', '.($hour < 12 ? self::AM : self::PM).' '
            .BanglaNumber::format(($hour % 12) ?: 12).':'.BanglaNumber::format($at->format('i'));
    }

    /**
     * "১ অক্টোবর ২০২৬". A date-only `Y-m-d` string keeps its own calendar day.
     */
    public static function date(CarbonInterface|string|null $value): string
    {
        $isDateOnly = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
        $at = $isDateOnly ? CarbonImmutable::createFromFormat('!Y-m-d', $value, self::TIMEZONE) : self::dhaka($value);

        if ($at === null) {
            return '';
        }

        return BanglaNumber::format((int) $at->format('j')).' '.self::MONTHS[(int) $at->format('n') - 1].' '.BanglaNumber::format($at->format('Y'));
    }

    /**
     * "অক্টোবর ২০২৬" for a `Y-m` month.
     */
    public static function month(string $month): string
    {
        [$year, $number] = explode('-', $month);

        return self::MONTHS[(int) $number - 1].' '.BanglaNumber::format($year);
    }

    private static function dhaka(CarbonInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        $at = $value instanceof CarbonInterface ? CarbonImmutable::instance($value) : CarbonImmutable::parse($value, 'UTC');

        return $at->setTimezone(self::TIMEZONE);
    }
}
