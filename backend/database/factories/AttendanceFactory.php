<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\StudentEnrolment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An attendance row for a fresh enrolment; tests usually pass `enrolment_id` (and the
 * matching student, section and year) so the row belongs to an enrolment they built.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Attendance>
 */
class AttendanceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enrolment_id' => StudentEnrolment::factory(),
            'student_id' => fn (array $attributes) => StudentEnrolment::findOrFail($attributes['enrolment_id'])->student_id,
            'section_id' => fn (array $attributes) => StudentEnrolment::findOrFail($attributes['enrolment_id'])->section_id,
            'academic_year_id' => fn (array $attributes) => StudentEnrolment::findOrFail($attributes['enrolment_id'])->academic_year_id,
            'date' => '2026-03-02',
            'status' => Attendance::STATUS_PRESENT,
            'remarks' => null,
            'marked_by' => null,
        ];
    }
}
