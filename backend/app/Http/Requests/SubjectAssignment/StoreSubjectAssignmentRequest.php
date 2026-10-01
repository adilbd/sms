<?php

namespace App\Http\Requests\SubjectAssignment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSubjectAssignmentRequest extends FormRequest
{
    // Access is enforced by the permission middleware in SubjectAssignmentController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Whether the teacher may teach in this section (active, category=teacher,
            // the section's shift), whether the subject is in the class curriculum and
            // whether the subject already has a teacher are checked in
            // SubjectAssignmentService, since they depend on saved state.
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->whereNull('deleted_at')],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->whereNull('deleted_at')],
            'staff_id' => ['required', 'integer', Rule::exists('staff', 'id')->whereNull('deleted_at')],
            // Defaults to the active academic year.
            'academic_year_id' => ['sometimes', 'nullable', 'integer', Rule::exists('academic_years', 'id')->whereNull('deleted_at')],
        ];
    }
}
