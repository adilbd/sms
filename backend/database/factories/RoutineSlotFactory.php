<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Period;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RoutineSlot>
 */
class RoutineSlotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'section_id' => Section::factory(),
            'day' => 'saturday',
            'period_id' => Period::factory(),
            'subject_id' => Subject::factory(),
            'staff_id' => null,
            'room' => null,
            'room_key' => null,
        ];
    }
}
