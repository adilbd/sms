<?php

namespace App\Http\Requests\Exam;

use App\Models\Exam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexExamRequest extends FormRequest
{
    // Access is enforced by the permission middleware in ExamController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => 'sometimes|nullable|integer|min:1',
            'type' => ['sometimes', 'nullable', 'string', Rule::in(Exam::TYPES)],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(Exam::STATUSES)],
            'search' => 'sometimes|nullable|string|max:100',
        ];
    }
}
