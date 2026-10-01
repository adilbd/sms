<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrolment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StudentEnrolment>
 */
class StudentEnrolmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'section_id' => Section::factory(),
            // Always the section's class, whichever section a test passes in.
            'class_id' => fn (array $attributes) => Section::findOrFail($attributes['section_id'])->class_id,
            'group' => null,
            'optional_subject_id' => null,
            'roll_number' => null,
            'status' => StudentEnrolment::STATUS_ACTIVE,
        ];
    }
}
