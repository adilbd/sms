<?php

namespace App\Http\Resources;

use App\Models\ClassSubject;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClassSubject */
class ClassSubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_id' => $this->class_id,
            'subject_id' => $this->subject_id,
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'group' => $this->group,
            'type' => $this->type,
            'sort_order' => $this->sort_order,
            'written_full' => $this->written_full,
            'written_pass' => $this->written_pass,
            'mcq_full' => $this->mcq_full,
            'mcq_pass' => $this->mcq_pass,
            'practical_full' => $this->practical_full,
            'practical_pass' => $this->practical_pass,
            'paper_group' => $this->paper_group,
        ];
    }
}
