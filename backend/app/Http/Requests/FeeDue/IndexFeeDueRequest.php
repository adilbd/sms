<?php

namespace App\Http\Requests\FeeDue;

use App\Models\FeeDue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexFeeDueRequest extends FormRequest
{
    // Access is enforced by the permission middleware in FeeDueController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => 'sometimes|nullable|integer|min:1',
            'section_id' => 'sometimes|nullable|integer|min:1',
            'class_id' => 'sometimes|nullable|integer|min:1',
            'academic_year_id' => 'sometimes|nullable|integer|min:1',
            'fee_head_id' => 'sometimes|nullable|integer|min:1',
            'month' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(FeeDue::STATUSES)],
            'per_page' => 'sometimes|nullable|integer|min:1|max:100',
        ];
    }
}
