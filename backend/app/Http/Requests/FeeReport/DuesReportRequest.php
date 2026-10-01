<?php

namespace App\Http\Requests\FeeReport;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DuesReportRequest extends FormRequest
{
    // Access is enforced by the permission middleware in FeeReportController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->whereNull('deleted_at')],
            'academic_year_id' => 'sometimes|nullable|integer|exists:academic_years,id',
            'month' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
        ];
    }
}
