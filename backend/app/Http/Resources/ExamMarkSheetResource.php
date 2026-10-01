<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A mark sheet: the exam subject, the section and one row per student on the sheet with
 * their saved marks (null until entered). Wraps the array ExamMarkService returns. The
 * student is a narrow shape (no contact or guardian details; the teacher role has
 * `enter-results` but not `view-students`).
 *
 * @property array{exam_subject: \App\Models\ExamSubject, section: \App\Models\Section, enrolments: \Illuminate\Support\Collection} $resource
 */
class ExamMarkSheetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'exam_subject' => new ExamSubjectResource($this->resource['exam_subject']),
            'section' => [
                'id' => $this->resource['section']->id,
                'class_id' => $this->resource['section']->class_id,
                'name' => $this->resource['section']->name,
                'code' => $this->resource['section']->code,
                'group' => $this->resource['section']->group,
            ],
            'students' => $this->resource['enrolments']->map(function ($enrolment) {
                $mark = $enrolment->examMarks->first();

                return [
                    'student_id' => $enrolment->student_id,
                    'enrolment_id' => $enrolment->id,
                    'student_code' => $enrolment->student->student_id,
                    'name_en' => $enrolment->student->name_en,
                    'name_bn' => $enrolment->student->name_bn,
                    'roll_number' => $enrolment->roll_number,
                    'group' => $enrolment->group,
                    'written' => $mark?->written,
                    'mcq' => $mark?->mcq,
                    'practical' => $mark?->practical,
                    'is_absent' => (bool) $mark?->is_absent,
                ];
            })->values(),
        ];
    }
}
