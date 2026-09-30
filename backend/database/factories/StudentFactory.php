<?php

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds only the profile row (no logins, no enrolment), so tests create exactly the
 * accounts and enrolments they care about.
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Student>
 */
class StudentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'student_id' => '2026'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'name_en' => fake()->name(),
            'name_bn' => null,
            'date_of_birth' => '2012-03-15',
            'gender' => 'male',
            'religion' => 'islam',
            'nationality' => 'Bangladeshi',
            'admission_date' => '2026-01-05',
            'status' => Student::STATUS_ACTIVE,
            'guardian_relation' => 'father',
            'guardian_name' => fake()->name('male'),
            'guardian_mobile' => '0171'.fake()->unique()->numerify('#######'),
        ];
    }

    public function left(): static
    {
        return $this->state(['status' => Student::STATUS_LEFT, 'leaving_date' => '2026-06-30']);
    }
}
