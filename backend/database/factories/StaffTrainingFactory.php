<?php

namespace Database\Factories;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StaffTraining>
 */
class StaffTrainingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'title' => fake()->randomElement(['ICT in Education', 'Classroom Management', 'CPD-1', 'CPD-2']),
            'organizer' => fake()->company(),
            'duration' => fake()->randomElement(['3 days', '1 week', '2 weeks']),
            'year' => (string) fake()->year(),
            'sort_order' => 0,
        ];
    }
}
