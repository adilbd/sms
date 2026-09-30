<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps either a real App\Models\ClassSection (GET .../class-teachers, one per
 * academic year) or the plain array App\Services\ClassTeacherService::assign()
 * returns (PUT .../class-teacher, which may not have a row to point at once
 * unassigned). Both shapes expose section_id/academic_year_id/staff, so a single
 * accessor works for either (see DelegatesToResource, used by both objects and arrays).
 */
class ClassTeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $this->staff;

        return [
            'section_id' => $this->section_id,
            'academic_year_id' => $this->academic_year_id,
            'staff' => $staff ? new StaffResource($staff) : null,
        ];
    }
}
