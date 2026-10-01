<?php

namespace App\Http\Resources;

use App\Models\ExamSubject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ExamSubject */
class ExamSubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'exam_id' => $this->exam_id,
            'class_id' => $this->class_id,
            'class' => new ClassResource($this->whenLoaded('class')),
            'subject_id' => $this->subject_id,
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'group' => $this->group,
            'type' => $this->type,
            'paper_group' => $this->paper_group,
            'written_full' => $this->written_full,
            'written_pass' => $this->written_pass,
            'mcq_full' => $this->mcq_full,
            'mcq_pass' => $this->mcq_pass,
            'practical_full' => $this->practical_full,
            'practical_pass' => $this->practical_pass,
            'exam_date' => $this->exam_date?->toDateString(),
            // The `time` column comes back as "H:i:s"; trimmed to "H:i" like ShiftResource.
            'start_time' => $this->start_time ? substr((string) $this->start_time, 0, 5) : null,
            'end_time' => $this->end_time ? substr((string) $this->end_time, 0, 5) : null,
            'sort_order' => $this->sort_order,
        ];
    }
}
