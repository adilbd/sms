<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A section's weekly routine as built by RoutineService (`academic_year`, `section`, `days`,
 * `periods`, `slots`). `section` is null for a student with no enrolment.
 */
class SectionRoutineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $routine = $this->resource;

        return [
            'kind' => 'section',
            'academic_year' => $routine['academic_year'] ? new AcademicYearResource($routine['academic_year']) : null,
            'section' => $routine['section'] ? new SectionResource($routine['section']) : null,
            'days' => $routine['days'],
            'periods' => PeriodResource::collection($routine['periods']),
            'slots' => RoutineSlotResource::collection($routine['slots']),
        ];
    }
}
