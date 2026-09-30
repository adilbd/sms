<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    public function definition(): array
    {
        $year = fake()->unique()->numberBetween(2020, 2099);

        return [
            'year' => $year,
            'name' => (string) $year,
            'code' => (string) $year,
            'start_date' => "{$year}-01-01",
            'end_date' => "{$year}-12-31",
            'is_active' => false,
            'description' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }
}
