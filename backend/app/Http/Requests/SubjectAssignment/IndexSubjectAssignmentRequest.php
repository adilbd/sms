<?php

namespace App\Http\Requests\SubjectAssignment;

use Illuminate\Foundation\Http\FormRequest;

class IndexSubjectAssignmentRequest extends FormRequest
{
    // Access is enforced by the permission middleware in SubjectAssignmentController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => 'sometimes|nullable|integer|min:1',
            'class_id' => 'sometimes|nullable|integer|min:1',
            'section_id' => 'sometimes|nullable|integer|min:1',
            'staff_id' => 'sometimes|nullable|integer|min:1',
            'subject_id' => 'sometimes|nullable|integer|min:1',
        ];
    }
}
