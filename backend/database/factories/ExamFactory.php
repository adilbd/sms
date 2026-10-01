<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Exam;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Dates default to inside the academic year the factory builds (or the given year).
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Exam>
 */
class ExamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'name_en' => 'Half Yearly Exam',
            'name_bn' => null,
            'code' => strtoupper(fake()->unique()->bothify('EX-####')),
            'type' => Exam::TYPE_HALF_YEARLY,
            'start_date' => fn (array $attributes) => AcademicYear::findOrFail($attributes['academic_year_id'])->year.'-06-01',
            'end_date' => fn (array $attributes) => AcademicYear::findOrFail($attributes['academic_year_id'])->year.'-06-15',
            'status' => Exam::STATUS_DRAFT,
            'published_at' => null,
        ];
    }

    public function markEntry(): static
    {
        return $this->state(['status' => Exam::STATUS_MARKS_ENTRY]);
    }
}
