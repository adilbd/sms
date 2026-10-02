<?php

namespace App\Http\Requests\SubjectAssignment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSectionSubjectTeachersRequest extends FormRequest
{
    // Access is enforced by the permission middleware in SectionController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->whereNull('deleted_at')],
        ];
    }
}
