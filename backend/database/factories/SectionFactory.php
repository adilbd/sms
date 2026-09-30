<?php

namespace Database\Factories;

use App\Models\Classes;
use App\Models\Shift;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Section>
 */
class SectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'class_id' => Classes::factory(),
            'shift_id' => Shift::factory(),
            'name' => 'Section '.fake()->unique()->randomLetter(),
            'code' => fake()->unique()->lexify('??').fake()->unique()->numerify('###'),
            'capacity' => 40,
            'group' => null,
            'description' => null,
            'is_active' => true,
        ];
    }
}
