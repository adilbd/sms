<?php

namespace App\Http\Requests\SubjectAssignment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncSectionSubjectTeachersRequest extends FormRequest
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
            'assignments' => ['present', 'array', 'max:60'],
            'assignments.*.subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->whereNull('deleted_at')],
            // null removes the subject's assignment. The staff rules (active, teacher,
            // the section's shift) and the curriculum check live in SubjectAssignmentService.
            'assignments.*.staff_id' => ['present', 'nullable', 'integer', Rule::exists('staff', 'id')->whereNull('deleted_at')],
        ];
    }
}
