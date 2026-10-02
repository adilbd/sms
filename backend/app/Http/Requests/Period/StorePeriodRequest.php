<?php

namespace App\Http\Requests\Period;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePeriodRequest extends FormRequest
{
    // Access is enforced by the permission middleware in PeriodController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shift_id' => ['required', 'integer', Rule::exists('shifts', 'id')],
            'number' => [
                'required', 'integer', 'min:1', 'max:99',
                Rule::unique('periods', 'number')->where('shift_id', $this->input('shift_id')),
            ],
            'name_en' => 'required|string|max:255',
            'name_bn' => 'required|string|max:255',
            // Asia/Dhaka wall-clock time, not UTC (see CLAUDE.md). The overlap and
            // end-after-start rules need saved state, so they live in PeriodService.
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'is_break' => 'sometimes|boolean',
        ];
    }
}
