<?php

namespace App\Http\Resources;

use App\Models\AdmissionRound;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdmissionRound
 *
 * One shape for the admin API and the public one (/api/public/admission-rounds), as the
 * round carries nothing private: the instructions are sanitized HTML meant for families.
 * `applications_count` only appears when the repository counted them (admin lists).
 */
class AdmissionRoundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_year_id' => $this->academic_year_id,
            'academic_year' => $this->whenLoaded('academicYear', fn () => [
                'id' => $this->academicYear->id,
                'year' => $this->academicYear->year,
            ]),
            'name_en' => $this->name_en,
            'name_bn' => $this->name_bn,
            'opens_at' => $this->opens_at?->toDateString(),
            'closes_at' => $this->closes_at?->toDateString(),
            'is_published' => $this->is_published,
            'is_open' => $this->isOpen(),
            'instructions_bn' => $this->instructions_bn,
            'instructions_en' => $this->instructions_en,
            'apply_url' => $this->url(),
            'classes' => $this->whenLoaded('classes', fn () => $this->classes->sortBy(fn ($row) => $row->class->number)->map(fn ($row) => [
                'class_id' => $row->class_id,
                'seats' => $row->seats,
                'requires_group' => $row->requiresGroup(),
                'class' => new ClassResource($row->class),
            ])->values()),
            'applications_count' => $this->whenCounted('applications'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
