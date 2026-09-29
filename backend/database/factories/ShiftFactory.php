<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Shift>
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        $nameEn = fake()->unique()->randomElement(['Morning', 'Day', 'Evening', 'Afternoon']).' '.fake()->unique()->numberBetween(1, 10000);

        return [
            'name_en' => $nameEn,
            'name_bn' => $nameEn,
            'slug' => Str::slug($nameEn),
            'start_time' => '07:00',
            'end_time' => '12:00',
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
