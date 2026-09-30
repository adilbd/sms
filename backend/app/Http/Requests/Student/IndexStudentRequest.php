<?php

namespace App\Http\Requests\Student;

use App\Models\Student;
use App\Support\AcademicGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexStudentRequest extends FormRequest
{
    // Access is enforced by the permission middleware in StudentController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => 'sometimes|nullable|integer|exists:academic_years,id',
            'class_id' => 'sometimes|nullable|integer|exists:classes,id',
            'section_id' => 'sometimes|nullable|integer|exists:sections,id',
            'shift_id' => 'sometimes|nullable|integer|exists:shifts,id',
            'group' => ['sometimes', 'nullable', Rule::in(AcademicGroup::VALUES)],
            'status' => ['sometimes', 'nullable', Rule::in(Student::STATUSES)],
            'search' => 'sometimes|nullable|string|max:100',
        ];
    }
}
