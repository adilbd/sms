<?php

namespace App\Http\Requests\FeeDue;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GenerateFeeDuesRequest extends FormRequest
{
    // Access is enforced by the permission middleware in FeeDueController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->whereNull('deleted_at')],
            // YYYY-MM; whether it is in the academic year is checked in FeeDueService.
            'month' => ['nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'class_id' => ['nullable', 'integer', Rule::exists('classes', 'id')->whereNull('deleted_at')],
            'section_id' => ['nullable', 'integer', Rule::exists('sections', 'id')->whereNull('deleted_at')],
            'fee_head_id' => ['nullable', 'integer', Rule::exists('fee_heads', 'id')->whereNull('deleted_at')],
            'exam_id' => ['nullable', 'integer', Rule::exists('exams', 'id')->whereNull('deleted_at')],
            // Only count what would be created, for the preview.
            'dry_run' => 'sometimes|boolean',
        ];
    }
}
