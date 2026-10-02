<?php

namespace App\Http\Requests\Section;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceClassTeachersRequest extends FormRequest
{
    // Access is enforced by the permission middleware in SectionController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->whereNull('deleted_at')],
            // An empty list removes every class teacher of the year.
            'teachers' => ['present', 'array', 'max:20'],
            // Whether each staff member may lead this section (active, category=teacher,
            // belongs to the section's shift) and that exactly one is main are checked in
            // ClassTeacherService, since they depend on saved state (see CLAUDE.md).
            'teachers.*.staff_id' => ['required', 'integer', 'distinct', Rule::exists('staff', 'id')->whereNull('deleted_at')],
            'teachers.*.is_main' => ['required', 'boolean'],
        ];
    }
}
