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
        ];
    }
}
