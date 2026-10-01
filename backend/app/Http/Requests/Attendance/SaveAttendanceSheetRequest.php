<?php

namespace App\Http\Requests\Attendance;

use App\Models\Attendance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveAttendanceSheetRequest extends FormRequest
{
    // Access is enforced by the permission middleware in AttendanceController; who may
    // save which section (its class teacher or an admin) and which dates are open is
    // checked in AttendanceService.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->whereNull('deleted_at')],
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'entries' => ['required', 'array', 'min:1', 'max:500'],
            'entries.*.student_id' => ['required', 'integer', 'distinct'],
            'entries.*.status' => ['required', 'string', Rule::in(Attendance::STATUSES)],
            'entries.*.remarks' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
