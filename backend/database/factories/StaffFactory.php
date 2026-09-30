<?php

namespace Database\Factories;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Staff>
 */
class StaffFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_id' => 'EMP-'.fake()->unique()->numberBetween(1000, 999999),
            'name_en' => fake()->name(),
            'name_bn' => null,
            'category' => Staff::CATEGORY_TEACHER,
            'position' => Staff::POSITION_TEACHER,
            'designation' => 'Assistant Teacher',
            'subject' => 'Bangla',
            'joining_date' => fake()->date(),
            'status' => Staff::STATUS_ACTIVE,
            'nationality' => 'Bangladeshi',
            'show_contact' => false,
            'is_published' => true,
        ];
    }

    public function former(): static
    {
        return $this->state([
            'status' => Staff::STATUS_RETIRED,
            'leaving_date' => fake()->date(),
        ]);
    }

    public function unpublished(): static
    {
        return $this->state(['is_published' => false]);
    }
}
