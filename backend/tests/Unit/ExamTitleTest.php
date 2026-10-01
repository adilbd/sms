<?php

namespace Tests\Unit;

use App\Support\ExamTitle;
use PHPUnit\Framework\TestCase;

class ExamTitleTest extends TestCase
{
    public function test_a_name_without_a_year_gets_it_appended(): void
    {
        $this->assertSame('অর্ধবার্ষিক পরীক্ষা ২০২৬', ExamTitle::withYear('অর্ধবার্ষিক পরীক্ষা', 2026, true));
        $this->assertSame('Half Yearly 2026', ExamTitle::withYear('Half Yearly', 2026));
    }

    public function test_a_name_with_the_year_in_either_digits_keeps_it_once(): void
    {
        $this->assertSame('অর্ধবার্ষিক পরীক্ষা ২০২৬', ExamTitle::withYear('অর্ধবার্ষিক পরীক্ষা ২০২৬', 2026, true));
        $this->assertSame('Half Yearly 2026', ExamTitle::withYear('Half Yearly 2026', 2026));
        $this->assertSame('Half Yearly ২০২৬', ExamTitle::withYear('Half Yearly ২০২৬', 2026));
        $this->assertSame('অর্ধবার্ষিক 2026', ExamTitle::withYear('অর্ধবার্ষিক 2026', '2026', true));
    }

    public function test_a_longer_number_containing_the_year_is_not_the_year(): void
    {
        $this->assertSame('Batch 20260 2026', ExamTitle::withYear('Batch 20260', 2026));
    }
}
