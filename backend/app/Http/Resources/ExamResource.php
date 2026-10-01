<?php

namespace App\Http\Resources;

use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Exam */
class ExamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_year_id' => $this->academic_year_id,
            'academic_year' => new AcademicYearResource($this->whenLoaded('academicYear')),
            'name_en' => $this->name_en,
            'name_bn' => $this->name_bn,
            'code' => $this->code,
            'type' => $this->type,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status,
            'published_at' => $this->published_at?->toIso8601String(),
            'class_ids' => $this->whenLoaded('classes', fn () => $this->classes->pluck('id')->values()),
            'classes' => ClassResource::collection($this->whenLoaded('classes')),
            'subjects_count' => $this->whenCounted('examSubjects'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
