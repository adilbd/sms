<?php

namespace Database\Seeders;

use App\Models\Shift;
use Illuminate\Database\Seeder;

/**
 * The two shifts this school runs. Matched by slug, so re-running this seeder never
 * duplicates rows. See docs/tasks/staff-module.md.
 */
class ShiftSeeder extends Seeder
{
    public function run(): void
    {
        Shift::updateOrCreate(['slug' => 'morning'], [
            'name_en' => 'Morning',
            'name_bn' => 'প্রভাতি',
            'start_time' => '07:00',
            'end_time' => '12:00',
            'sort_order' => 0,
            'is_active' => true,
        ]);

        Shift::updateOrCreate(['slug' => 'day'], [
            'name_en' => 'Day',
            'name_bn' => 'দিবা',
            'start_time' => '12:30',
            'end_time' => '17:30',
            'sort_order' => 1,
            'is_active' => true,
        ]);
    }
}
