<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * The one rule for the day an enrolment begins (`student_enrolments.enrolled_on`): the
 * student's admission date, raised to the academic year's start and capped at its end.
 * `created_at` is data-entry time, not the joining date, so it plays no part. Used by the
 * enrolled_on migration's backfill and by EnrolmentService.
 */
class EnrolmentStart
{
    /**
     * All arguments are dates (Y-m-d strings or Carbon dates); returns a Y-m-d string.
     */
    public static function for(CarbonInterface|string $yearStart, CarbonInterface|string $yearEnd, CarbonInterface|string|null $admissionDate): string
    {
        $start = self::day($yearStart);
        $end = self::day($yearEnd);
        $admitted = $admissionDate === null ? $start : self::day($admissionDate);

        return min(max($admitted, $start), $end);
    }

    private static function day(CarbonInterface|string $date): string
    {
        return $date instanceof CarbonInterface ? $date->toDateString() : substr($date, 0, 10);
    }
}
