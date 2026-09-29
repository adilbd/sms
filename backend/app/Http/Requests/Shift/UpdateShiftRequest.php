<?php

namespace App\Http\Requests\Shift;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateShiftRequest extends FormRequest
{
    // Access is enforced by the permission middleware in ShiftController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_en' => 'sometimes|string|max:255',
            'name_bn' => 'sometimes|string|max:255',
            'slug' => ['sometimes', 'alpha_dash:ascii', 'max:255', Rule::unique('shifts', 'slug')->ignore($this->route('shift'))],
            'start_time' => 'sometimes|nullable|date_format:H:i',
            'end_time' => 'sometimes|nullable|date_format:H:i',
            'sort_order' => 'sometimes|integer|min:0|max:65535',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
