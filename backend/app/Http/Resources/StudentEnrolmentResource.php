<?php

namespace App\Http\Resources;

use App\Models\StudentEnrolment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StudentEnrolment */
class StudentEnrolmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'academic_year_id' => $this->academic_year_id,
            'class_id' => $this->class_id,
            'section_id' => $this->section_id,
            'group' => $this->group,
            'optional_subject_id' => $this->optional_subject_id,
            'roll_number' => $this->roll_number,
            'status' => $this->status,
            'academic_year' => new AcademicYearResource($this->whenLoaded('academicYear')),
            'class' => new ClassResource($this->whenLoaded('class')),
            'section' => new SectionResource($this->whenLoaded('section')),
            'optional_subject' => new SubjectResource($this->whenLoaded('optionalSubject')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
