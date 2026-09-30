<?php

namespace Database\Factories;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StaffEducation>
 */
class StaffEducationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'degree' => fake()->randomElement(['B.A', 'M.A', 'B.Sc', 'M.Sc', 'B.Ed']),
            'institution' => null,
            'board_university' => fake()->city().' University',
            'passing_year' => (string) fake()->year(),
            'result' => fake()->randomElement(['1st Class', '2nd Class', 'CGPA 3.5']),
            'sort_order' => 0,
        ];
    }
}
