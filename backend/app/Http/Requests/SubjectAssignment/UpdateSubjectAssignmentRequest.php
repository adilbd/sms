<?php

namespace App\Http\Requests\SubjectAssignment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectAssignmentRequest extends FormRequest
{
    // Access is enforced by the permission middleware in SubjectAssignmentController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The section, subject and year identify the assignment, so they can't change.
            'section_id' => ['prohibited'],
            'subject_id' => ['prohibited'],
            'academic_year_id' => ['prohibited'],
            'staff_id' => ['required', 'integer', Rule::exists('staff', 'id')->whereNull('deleted_at')],
        ];
    }
}
