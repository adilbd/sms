<?php

namespace App\Http\Requests\Attendance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The optional `?month=YYYY-MM` of a single student's attendance (staff view and the
 * student's and guardian's own views).
 */
class MonthAttendanceRequest extends FormRequest
{
    // Access is enforced by the permission/role middleware on the routes; whose records
    // may be read is checked in AttendanceService.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'month' => ['sometimes', 'nullable', 'date_format:Y-m'],
        ];
    }
}
