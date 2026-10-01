<?php

namespace Tests\Unit;

use App\Support\BanglaDate;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BanglaDateTest extends TestCase
{
    public function test_a_utc_timestamp_is_shown_in_dhaka_with_bangla_digits_month_and_period(): void
    {
        // 15:07 in Asia/Dhaka (UTC+6) is 09:07 UTC.
        $this->assertSame('১ অক্টোবর ২০২৬, অপরাহ্ন ৩:০৭', BanglaDate::dateTime('2026-10-01 09:07'));
        $this->assertSame('১ অক্টোবর ২০২৬, অপরাহ্ন ৩:০৭', BanglaDate::dateTime(CarbonImmutable::parse('2026-10-01 15:07', 'Asia/Dhaka')));
        $this->assertSame('১ অক্টোবর ২০২৬, অপরাহ্ন ৩:০৭', BanglaDate::dateTime('2026-10-01T09:07:00+00:00'));
    }

    public function test_midnight_and_noon_use_twelve_and_cross_the_date_line(): void
    {
        // 18:05 UTC is 00:05 the next day in Dhaka.
        $this->assertSame('২ অক্টোবর ২০২৬, পূর্বাহ্ন ১২:০৫', BanglaDate::dateTime('2026-10-01 18:05'));
        $this->assertSame('১ অক্টোবর ২০২৬, অপরাহ্ন ১২:০০', BanglaDate::dateTime('2026-10-01 06:00'));
        $this->assertSame('১ জানুয়ারি ২০২৬, পূর্বাহ্ন ১১:৫৯', BanglaDate::dateTime('2026-01-01 05:59'));
    }

    public function test_it_never_contains_latin_month_names_digits_or_am_pm(): void
    {
        foreach (range(1, 12) as $month) {
            $text = BanglaDate::dateTime(sprintf('2026-%02d-15 07:30', $month));

            $this->assertDoesNotMatchRegularExpression('/[0-9A-Za-z]/', $text);
        }
    }

    public function test_date_only_values_keep_their_calendar_day(): void
    {
        $this->assertSame('১ নভেম্বর ২০২৬', BanglaDate::date('2026-11-01'));
        $this->assertSame('অক্টোবর ২০২৬', BanglaDate::month('2026-10'));
        $this->assertSame('', BanglaDate::dateTime(null));
        $this->assertSame('', BanglaDate::date(''));
    }

    public function test_the_js_counterpart_uses_the_same_words(): void
    {
        $js = file_get_contents(dirname(__DIR__, 2).'/resources/js/admin/utils/banglaDate.js');

        foreach ([...BanglaDate::MONTHS, BanglaDate::AM, BanglaDate::PM, 'Asia/Dhaka'] as $word) {
            $this->assertStringContainsString($word, $js);
        }
    }
}
