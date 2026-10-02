<?php

namespace Database\Factories;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Period>
 */
class PeriodFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'number' => fake()->unique()->numberBetween(1, 60000),
            'name_en' => 'Period',
            'name_bn' => 'পিরিয়ড',
            'start_time' => '08:00:00',
            'end_time' => '08:45:00',
            'is_break' => false,
        ];
    }

    public function break(): static
    {
        return $this->state(['is_break' => true, 'name_en' => 'Tiffin', 'name_bn' => 'টিফিন']);
    }
}
