<?php

namespace Database\Factories;

use App\Models\AcademicYear;
use App\Models\Section;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Homework>
 */
class HomeworkFactory extends Factory
{
    public function definition(): array
    {
        return [
            'academic_year_id' => AcademicYear::factory(),
            'section_id' => Section::factory(),
            'subject_id' => Subject::factory(),
            'staff_id' => null,
            'title' => fake()->sentence(4),
            'details' => '<p>'.fake()->sentence().'</p>',
            'assigned_on' => '2026-10-01',
            'due_on' => '2026-10-08',
            'attachment_path' => null,
            'attachment_name' => null,
        ];
    }
}
