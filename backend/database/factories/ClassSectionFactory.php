<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Section;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ClassSection>
 */
class ClassSectionFactory extends Factory
{
    /**
     * The section (and so its class) is only created when the test doesn't pass its own, and
     * `class_id` follows the section, so building many rows never makes extra classes (class
     * numbers are limited to 1-12) or sections nobody uses.
     */
    public function definition(): array
    {
        return [
            'section_id' => Section::factory(),
            'class_id' => fn (array $attributes) => Section::query()->findOrFail($attributes['section_id'])->class_id,
            'academic_year_id' => AcademicYear::factory(),
            'staff_id' => null,
        ];
    }
}
