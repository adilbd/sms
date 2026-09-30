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
        // Only take the active flag when no other year holds it, so a re-run never
        // leaves two active years or overrides an admin's choice.
        $otherActive = AcademicYear::where('is_active', true)->where('year', '!=', 2026)->exists();

        $year = AcademicYear::firstOrNew(['year' => 2026]);
        $year->fill([
            'name' => '2026',
            'code' => '2026',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
        ]);

        if (! $year->exists) {
            $year->is_active = ! $otherActive;
        }

        $year->save();
    }
}
