<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps either a real App\Models\ClassSection (GET .../class-teachers, one per
 * academic year) or the plain object App\Services\ClassTeacherService::assign()
 * returns (PUT .../class-teacher, which may not have a row to point at once
 * unassigned). Both expose section_id/academic_year_id/staff properties, so a single
 * accessor works for either.
 *
 * These endpoints only need view-classes/edit-classes (the teacher role has the former
 * but not view-teachers), so the staff member is a narrow shape: never nid, date of
 * birth, addresses, mpo_index or contact details.
 */
class ClassTeacherResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $staff = $this->staff;

        return [
            'section_id' => $this->section_id,
            'academic_year_id' => $this->academic_year_id,
            'staff' => $staff ? [
                'id' => $staff->id,
                'name_en' => $staff->name_en,
                'name_bn' => $staff->name_bn,
                'designation' => $staff->designation,
                'photo_url' => $staff->photoUrl(),
            ] : null,
        ];
    }
}
