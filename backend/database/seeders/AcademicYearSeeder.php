<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use Illuminate\Database\Seeder;

/**
 * The current academic year, active by default. Matched by year, so re-running this
 * seeder never duplicates rows. See docs/tasks/academic-structure.md.
 */
class AcademicYearSeeder extends Seeder
{
    public function run(): void
    {
        AcademicYear::updateOrCreate(['year' => 2026], [
            'name' => '2026',
            'code' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'is_active' => true,
        ]);
    }
}
