<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Subject>
 */
class SubjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'code' => strtoupper(fake()->unique()->bothify('SUB-###')),
            'type' => 'theory',
            'total_marks' => 100,
            'pass_marks' => 40,
            'description' => null,
            'is_active' => true,
        ];
    }
}
