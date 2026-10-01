<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAttendanceSheetRequest extends FormRequest
{
    // Access is enforced by the permission middleware in AttendanceController; who may
    // read which section (its class teacher or an admin) is checked in AttendanceService.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->whereNull('deleted_at')],
            // An Asia/Dhaka calendar date; today when left out.
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ];
    }
}
