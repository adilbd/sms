<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAttendanceReportRequest extends FormRequest
{
    // Access is enforced by the permission middleware in AttendanceController; who may
    // read which section is checked in AttendanceService.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->whereNull('deleted_at')],
            // YYYY-MM, the current month when left out.
            'month' => ['sometimes', 'nullable', 'date_format:Y-m'],
        ];
    }
}
