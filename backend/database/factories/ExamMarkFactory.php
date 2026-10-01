<?php

namespace Database\Factories;

use App\Models\ExamSubject;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrolment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExamMark>
 */
class ExamMarkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'exam_subject_id' => ExamSubject::factory(),
            'student_id' => Student::factory(),
            // Enrolled in a new section of the subject's class, in the exam's year.
            'enrolment_id' => function (array $attributes) {
                $subject = ExamSubject::with('exam')->findOrFail($attributes['exam_subject_id']);

                return StudentEnrolment::factory()->create([
                    'student_id' => $attributes['student_id'],
                    'academic_year_id' => $subject->exam->academic_year_id,
                    'section_id' => Section::factory()->create(['class_id' => $subject->class_id])->id,
                ])->id;
            },
            'written' => 60,
            'mcq' => null,
            'practical' => null,
            'is_absent' => false,
            'entered_by' => null,
        ];
    }
}
