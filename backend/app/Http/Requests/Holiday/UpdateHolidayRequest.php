<?php

namespace App\Http\Requests\Holiday;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHolidayRequest extends FormRequest
{
    // Access is enforced by the permission middleware in HolidayController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'date_format:Y-m-d', Rule::unique('holidays', 'date')->ignore($this->route('holiday'))],
            'name_en' => 'sometimes|nullable|string|max:255',
            'name_bn' => 'sometimes|nullable|string|max:255',
        ];
    }
}
