<?php

namespace App\Http\Requests\Homework;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Section, subject and year can't change (assign new homework instead). Who may edit, and
 * until when, is checked in HomeworkService.
 */
class UpdateHomeworkRequest extends FormRequest
{
    // Access is enforced by the permission middleware in HomeworkController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['prohibited'],
            'section_id' => ['prohibited'],
            'subject_id' => ['prohibited'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'details' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'assigned_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'due_on' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'attachment' => ['sometimes', 'nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'remove_attachment' => ['sometimes', 'boolean'],
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
