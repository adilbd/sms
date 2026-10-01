<?php

namespace Database\Factories;

use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ClassSubject>
 */
class ClassSubjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'class_id' => Classes::factory(),
            'subject_id' => Subject::factory(),
            'group' => null,
            'type' => ClassSubject::TYPE_COMPULSORY,
            'sort_order' => 0,
            'written_full' => 100,
            'written_pass' => 33,
        ];
    }
}
