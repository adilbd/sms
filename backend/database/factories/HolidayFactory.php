<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Holiday>
 */
class HolidayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'date' => fake()->unique()->date(),
            'name_en' => fake()->words(2, true),
            'name_bn' => null,
            'academic_year_id' => AcademicYear::factory(),
        ];
    }
}
