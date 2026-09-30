<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ClassSection>
 */
class ClassSectionFactory extends Factory
{
    public function definition(): array
    {
        $section = Section::factory()->create();

        return [
            'class_id' => $section->class_id,
            'section_id' => $section->id,
            'academic_year_id' => AcademicYear::factory(),
            'staff_id' => null,
        ];
    }
}
