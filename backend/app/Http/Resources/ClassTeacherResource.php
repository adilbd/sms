<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ClassSection
 *
 * One class teacher of a section for a year (GET and PUT .../class-teachers): the staff
 * member and whether they are the main teacher.
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
            'staff_id' => $this->staff_id,
            'is_main' => (bool) $this->is_main,
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
