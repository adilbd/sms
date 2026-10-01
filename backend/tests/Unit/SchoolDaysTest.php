<?php

namespace Tests\Unit;

use App\Support\SchoolDays;
use PHPUnit\Framework\TestCase;

class SchoolDaysTest extends TestCase
{
    public function test_weekday_names_are_lowercase_english(): void
    {
        $this->assertSame('thursday', SchoolDays::weekday('2026-10-08'));
        $this->assertSame('friday', SchoolDays::weekday('2026-10-09'));
        $this->assertSame('saturday', SchoolDays::weekday('2026-10-10'));
    }

    public function test_between_skips_weekly_and_listed_holidays(): void
    {
        $this->assertSame(
            ['2026-10-01', '2026-10-03', '2026-10-04', '2026-10-06'],
            SchoolDays::between('2026-10-01', '2026-10-06', ['friday'], ['2026-10-05' => 'Holiday'])
        );

        $this->assertSame(
            ['2026-10-01', '2026-10-04'],
            SchoolDays::between('2026-10-01', '2026-10-04', ['friday', 'saturday'], [])
        );
    }

    public function test_between_is_inclusive_and_handles_a_single_day_and_an_empty_range(): void
    {
        $this->assertSame(['2026-10-08'], SchoolDays::between('2026-10-08', '2026-10-08', ['friday'], []));
        $this->assertSame([], SchoolDays::between('2026-10-09', '2026-10-09', ['friday'], []));
        $this->assertSame([], SchoolDays::between('2026-10-09', '2026-10-01', ['friday'], []));
    }

    public function test_percentage_is_a_two_decimal_string(): void
    {
        $this->assertSame('42.86', SchoolDays::percentage(3, 7));
        $this->assertSame('100.00', SchoolDays::percentage(7, 7));
        $this->assertSame('0.00', SchoolDays::percentage(0, 7));
        $this->assertSame('0.00', SchoolDays::percentage(0, 0));
        $this->assertSame('66.67', SchoolDays::percentage(2, 3));
    }
}
