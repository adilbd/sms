<?php

namespace Database\Factories;

use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\Subject;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ExamSubject>
 */
class ExamSubjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'exam_id' => Exam::factory(),
            'class_id' => Classes::factory(),
            'subject_id' => Subject::factory(),
            'group' => null,
            'type' => ClassSubject::TYPE_COMPULSORY,
            'paper_group' => null,
            'written_full' => 100,
            'written_pass' => 33,
            'sort_order' => 0,
        ];
    }

    /** Written 50/17, MCQ 25/8 and practical 25/8, like Physics at SSC. */
    public function threeParts(): static
    {
        return $this->state([
            'written_full' => 50, 'written_pass' => 17,
            'mcq_full' => 25, 'mcq_pass' => 8,
            'practical_full' => 25, 'practical_pass' => 8,
        ]);
    }
}
