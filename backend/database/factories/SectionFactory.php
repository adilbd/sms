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
    /** Counts up for the whole process, so names and codes never repeat (like AcademicYearFactory's years). */
    private static int $sequence = 0;

    public function definition(): array
    {
        $n = ++self::$sequence;

        return [
            'class_id' => Classes::factory(),
            'shift_id' => Shift::factory(),
            'name' => "Section {$n}",
            'code' => "S{$n}",
            'capacity' => 40,
            'group' => null,
            'description' => null,
            'is_active' => true,
        ];
    }
}
