<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One teacher's weekly routine as built by RoutineService (`academic_year`, `staff`, `days`,
 * `slots`, each slot with its period and section). `staff` is a narrow shape, null for a
 * teacher with no staff link.
 */
class TeacherRoutineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $routine = $this->resource;
        $staff = $routine['staff'];

        return [
            'kind' => 'teacher',
            'academic_year' => $routine['academic_year'] ? new AcademicYearResource($routine['academic_year']) : null,
            'staff' => $staff ? [
                'id' => $staff->id,
                'name_en' => $staff->name_en,
                'name_bn' => $staff->name_bn,
                'designation' => $staff->designation,
            ] : null,
            'days' => $routine['days'],
            'slots' => RoutineSlotResource::collection($routine['slots']),
        ];
    }
}
