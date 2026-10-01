<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\FeeHead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FeeRate>
 */
class FeeRateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'fee_head_id' => FeeHead::factory(),
            'class_id' => Classes::factory(),
            'academic_year_id' => AcademicYear::factory(),
            'group' => null,
            'amount' => '800.00',
            'due_day' => null,
        ];
    }
}
