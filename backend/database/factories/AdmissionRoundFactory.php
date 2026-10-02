<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An open, published round by default (it opened last week and closes in a month).
 *
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AdmissionRound>
 */
class AdmissionRoundFactory extends Factory
{
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'name_en' => 'Admission '.fake()->unique()->numberBetween(1, 100000),
            'name_bn' => 'ভর্তি',
            'opens_at' => now('Asia/Dhaka')->subWeek()->toDateString(),
            'closes_at' => now('Asia/Dhaka')->addMonth()->toDateString(),
            'is_published' => true,
            'instructions_bn' => null,
            'instructions_en' => null,
        ];
    }

    public function unpublished(): static
    {
        return $this->state(['is_published' => false]);
    }

    public function closed(): static
    {
        return $this->state([
            'opens_at' => now('Asia/Dhaka')->subMonths(2)->toDateString(),
            'closes_at' => now('Asia/Dhaka')->subDay()->toDateString(),
        ]);
    }

    public function upcoming(): static
    {
        return $this->state([
            'opens_at' => now('Asia/Dhaka')->addDay()->toDateString(),
            'closes_at' => now('Asia/Dhaka')->addMonth()->toDateString(),
        ]);
    }
}
