<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Which calendar dates are school days: every date in a range except the weekly holidays
 * (weekday names, e.g. "friday") and the listed holiday dates. Dates are Asia/Dhaka
 * calendar dates as `Y-m-d` strings; no time zone is involved. Stateless, no database.
 */
class SchoolDays
{
    /**
     * The lowercase English weekday name of a `Y-m-d` date.
     */
    public static function weekday(string $date): string
    {
        return strtolower(CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC')->englishDayOfWeek);
    }

    /**
     * The school days from $from to $to inclusive, oldest first.
     *
     * @param  list<string>  $weeklyHolidays
     * @param  array<string, mixed>  $holidays  keyed by date
     * @return list<string>
     */
    public static function between(string $from, string $to, array $weeklyHolidays, array $holidays): array
    {
        $days = [];
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');

        while ($day <= $last) {
            $date = $day->toDateString();

            if (! in_array(strtolower($day->englishDayOfWeek), $weeklyHolidays, true) && ! array_key_exists($date, $holidays)) {
                $days[] = $date;
            }

            $day = $day->addDay();
        }

        return $days;
    }

    /**
     * (present + late) as a percentage of $schoolDays, as a `decimal:2` string; "0.00"
     * when there are no school days.
     */
    public static function percentage(int $attended, int $schoolDays): string
    {
        if ($schoolDays <= 0) {
            return '0.00';
        }

        return number_format(round($attended * 100 / $schoolDays, 2), 2, '.', '');
    }
}
