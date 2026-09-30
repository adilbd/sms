<?php

namespace Database\Factories;

use App\Models\Classes;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Classes>
 */
class ClassesFactory extends Factory
{
    protected $model = Classes::class;

    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(Classes::MIN_NUMBER, Classes::MAX_NUMBER);

        return [
            'number' => $number,
            'name' => "Class {$number}",
            'name_bn' => null,
            'code' => 'C'.str_pad((string) $number, 2, '0', STR_PAD_LEFT).fake()->unique()->numerify('##'),
            'description' => null,
            'display_order' => $number,
            'is_active' => true,
        ];
    }
}
