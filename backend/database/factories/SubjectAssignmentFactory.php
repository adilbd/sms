<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SubjectAssignment>
 */
class SubjectAssignmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'subject_id' => Subject::factory(),
            'section_id' => Section::factory(),
            // Follows the section (given or created), like the service fills it.
            'class_id' => fn (array $attributes) => Section::findOrFail($attributes['section_id'])->class_id,
            'academic_year_id' => AcademicYear::factory(),
        ];
    }
}
