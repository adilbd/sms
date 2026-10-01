<?php

namespace Database\Factories;

use App\Models\Exam;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrolment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A passing 4.00 result with an empty breakdown, enrolled in a new section.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExamResult>
 */
class ExamResultFactory extends Factory
{
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
            'student_id' => Student::factory(),
            'enrolment_id' => function (array $attributes) {
                $section = Section::factory()->create();

                return StudentEnrolment::factory()->create([
                    'student_id' => $attributes['student_id'],
                    'academic_year_id' => Exam::findOrFail($attributes['exam_id'])->academic_year_id,
                    'section_id' => $section->id,
                ])->id;
            },
            'class_id' => fn (array $attributes) => StudentEnrolment::findOrFail($attributes['enrolment_id'])->class_id,
            'section_id' => fn (array $attributes) => StudentEnrolment::findOrFail($attributes['enrolment_id'])->section_id,
            'total_obtained' => 400,
            'total_full' => 500,
            'gpa' => 4,
            'grade' => 'A',
            'is_pass' => true,
            'failed_count' => 0,
            'passed_count' => 0,
            'class_position' => 1,
            'section_position' => 1,
            'subjects' => [],
        ];
    }
}
