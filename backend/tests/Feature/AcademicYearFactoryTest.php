<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicYearFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_fifty_factory_years_never_collide_with_hardcoded_ones(): void
    {
        foreach ([2024, 2025, 2026, 2027] as $year) {
            AcademicYear::factory()->create(['year' => $year]);
        }

        $years = AcademicYear::factory()->count(50)->create();

        $this->assertCount(54, AcademicYear::query()->distinct()->pluck('year'));
        $this->assertCount(54, AcademicYear::query()->distinct()->pluck('code'));

        foreach ($years as $academicYear) {
            $this->assertSame((string) $academicYear->year, $academicYear->code);
            $this->assertSame((string) $academicYear->year, $academicYear->name);
            $this->assertSame("{$academicYear->year}-01-01", $academicYear->start_date->toDateString());
            $this->assertSame("{$academicYear->year}-12-31", $academicYear->end_date->toDateString());
        }
    }

    public function test_an_overridden_year_drives_the_name_code_and_dates(): void
    {
        $year = AcademicYear::factory()->create(['year' => 2027]);

        $this->assertSame('2027', $year->name);
        $this->assertSame('2027', $year->code);
        $this->assertSame('2027-12-31', $year->end_date->toDateString());
    }

    public function test_it_skips_years_that_already_exist(): void
    {
        $first = AcademicYear::factory()->create();
        AcademicYear::factory()->create(['year' => $first->year + 1]);

        $this->assertSame($first->year + 2, AcademicYear::factory()->create()->year);
    }
}
