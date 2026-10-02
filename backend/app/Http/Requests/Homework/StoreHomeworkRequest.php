<?php

namespace App\Http\Requests\Homework;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Whether the caller may assign homework to this section and subject, and whether the
 * subject is in the section's curriculum, are checked in HomeworkService.
 */
class StoreHomeworkRequest extends FormRequest
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
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->whereNull('deleted_at')],
            'subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->whereNull('deleted_at')],
            'title' => ['required', 'string', 'max:255'],
            'details' => ['sometimes', 'nullable', 'string', 'max:20000'],
            // Defaults to today (Asia/Dhaka).
            'assigned_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'due_on' => ['required', 'date_format:Y-m-d'],
            'attachment' => ['sometimes', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'attachment.mimes' => 'The attachment must be a PDF, JPG or PNG file.',
            'attachment.max' => 'The attachment must not be larger than 5 MB.',
        ];
    }
}
