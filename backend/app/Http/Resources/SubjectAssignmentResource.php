<?php

namespace App\Http\Resources;

use App\Models\SubjectAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SubjectAssignment
 *
 * Readable with view-classes (the teacher role has it, but not view-teachers), so the
 * staff member is a narrow shape: never nid, date of birth, addresses, mpo_index or
 * contact details (same as ClassTeacherResource).
 */
class SubjectAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_id' => $this->staff_id,
            'subject_id' => $this->subject_id,
            'class_id' => $this->class_id,
            'section_id' => $this->section_id,
            'academic_year_id' => $this->academic_year_id,
            'staff' => $this->relationLoaded('staff') && $this->staff ? [
                'id' => $this->staff->id,
                'name_en' => $this->staff->name_en,
                'name_bn' => $this->staff->name_bn,
                'designation' => $this->staff->designation,
                'photo_url' => $this->staff->photoUrl(),
            ] : null,
            'subject' => new SubjectResource($this->whenLoaded('subject')),
            'section' => new SectionResource($this->whenLoaded('section')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
