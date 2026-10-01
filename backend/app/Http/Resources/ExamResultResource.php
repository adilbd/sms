<?php

namespace App\Http\Resources;

use App\Models\ExamResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExamResult
 *
 * A processed result. The per-unit `subjects` breakdown is opt-in, like
 * PostResource::withBody(): the tabulation rows leave it out, the single-student breakdown,
 * the report cards and the student's/guardian's own results ask for it. The student is a
 * narrow shape (no contact or guardian details), because the teacher role has
 * `view-results` but not `view-students`.
 */
class ExamResultResource extends JsonResource
{
    public bool $withSubjects = false;

    public function withSubjects(bool $withSubjects = true): static
    {
        $this->withSubjects = $withSubjects;

        return $this;
    }

    /**
     * A collection whose items all carry the breakdown.
     */
    public static function collectionWithSubjects(mixed $items): AnonymousResourceCollection
    {
        $collection = static::collection($items);
        $collection->collection->each(fn (ExamResultResource $resource) => $resource->withSubjects());

        return $collection;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'student_id' => $this->student_id,
            'enrolment_id' => $this->enrolment_id,
            'class_id' => $this->class_id,
            'section_id' => $this->section_id,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'student_code' => $this->student->student_id,
                'name_en' => $this->student->name_en,
                'name_bn' => $this->student->name_bn,
                'photo_url' => $this->student->photoUrl(),
            ]),
            'roll_number' => $this->whenLoaded('enrolment', fn () => $this->enrolment->roll_number),
            'group' => $this->whenLoaded('enrolment', fn () => $this->enrolment->group),
            'class' => new ClassResource($this->whenLoaded('class')),
            'section' => new SectionResource($this->whenLoaded('section')),
            'exam' => new ExamResource($this->whenLoaded('exam')),
            'total_obtained' => $this->total_obtained,
            'total_full' => $this->total_full,
            'gpa' => $this->gpa,
            'grade' => $this->grade,
            'is_pass' => $this->is_pass,
            'failed_count' => $this->failed_count,
            'passed_count' => $this->passed_count,
            'class_position' => $this->class_position,
            'section_position' => $this->section_position,
            'subjects' => $this->when($this->withSubjects, fn () => $this->subjects),
            'processed_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
