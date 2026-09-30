<?php

namespace Database\Seeders;

use App\Models\Classes;
use App\Models\Section;
use App\Models\Shift;
use Illuminate\Database\Seeder;

/**
 * Section A for every class, in every shift. Matched by (class_id, shift_id, code), so
 * re-running this seeder never duplicates rows. See docs/tasks/academic-structure.md.
 */
class SectionSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = Shift::all();

        foreach (Classes::all() as $class) {
            foreach ($shifts as $shift) {
                Section::updateOrCreate(
                    ['class_id' => $class->id, 'shift_id' => $shift->id, 'code' => 'A'],
                    ['name' => 'Section A', 'capacity' => 40, 'is_active' => true]
                );
            }
        }
    }
}
