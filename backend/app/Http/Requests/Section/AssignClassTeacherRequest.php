<?php

namespace App\Http\Requests\Section;

use Illuminate\Foundation\Http\FormRequest;

class AssignClassTeacherRequest extends FormRequest
{
    // Access is enforced by the permission middleware in SectionController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => 'required|integer|exists:academic_years,id',
            // Whether this particular staff member may lead this section (active,
            // category=teacher, belongs to the section's shift, not already leading
            // another section this year) is checked in ClassTeacherService, since it
            // depends on the staff record's current state (see CLAUDE.md).
            'staff_id' => 'nullable|integer|exists:staff,id',
        ];
    }
}
