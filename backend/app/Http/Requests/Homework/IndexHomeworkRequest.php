<?php

namespace App\Http\Requests\Homework;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexHomeworkRequest extends FormRequest
{
    // Access is enforced by the permission middleware in HomeworkController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['sometimes', 'nullable', 'integer', Rule::exists('academic_years', 'id')->whereNull('deleted_at')],
            'section_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'subject_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            // A range on the due date.
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'due' => ['sometimes', 'nullable', Rule::in(['upcoming', 'past'])],
            'per_page' => ['sometimes', 'nullable', 'integer'],
            'page' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
