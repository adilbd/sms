<?php

namespace App\Http\Requests\Exam;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexExamResultRequest extends FormRequest
{
    // Access is enforced by the permission middleware in ExamResultController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'class_id' => ['sometimes', 'nullable', 'integer', Rule::exists('classes', 'id')->whereNull('deleted_at')],
            'section_id' => ['sometimes', 'nullable', 'integer', Rule::exists('sections', 'id')->whereNull('deleted_at')],
            // Opt in to the per-subject breakdown on every row (the report cards print from it).
            'with_subjects' => ['sometimes', 'nullable', 'boolean'],
            'per_page' => ['sometimes', 'nullable', 'integer'],
            'page' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
