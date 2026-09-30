<?php

namespace App\Http\Requests\Shift;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShiftRequest extends FormRequest
{
    // Access is enforced by the permission middleware in ShiftController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_en' => 'required|string|max:255',
            'name_bn' => 'required|string|max:255',
            'slug' => ['required', 'alpha_dash:ascii', 'max:255', Rule::unique('shifts', 'slug')],
            // Asia/Dhaka wall-clock time, not UTC (see CLAUDE.md).
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i',
            'sort_order' => 'sometimes|integer|min:0|max:65535',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
