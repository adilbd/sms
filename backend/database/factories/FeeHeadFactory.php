<?php

namespace Database\Factories;

use App\Models\FeeHead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FeeHead>
 */
class FeeHeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name_en' => fake()->words(2, true),
            'name_bn' => null,
            'code' => strtoupper(fake()->unique()->bothify('FH-####')),
            'kind' => FeeHead::KIND_MONTHLY,
            'is_active' => true,
        ];
    }

    public function monthly(): static
    {
        return $this->state(['kind' => FeeHead::KIND_MONTHLY]);
    }

    public function oneTime(): static
    {
        return $this->state(['kind' => FeeHead::KIND_ONE_TIME]);
    }

    public function perExam(): static
    {
        return $this->state(['kind' => FeeHead::KIND_PER_EXAM]);
    }
}
