<?php

namespace App\Http\Requests\Holiday;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHolidayRequest extends FormRequest
{
    // Access is enforced by the permission middleware in HolidayController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // An Asia/Dhaka calendar date. The academic year comes from it (HolidayService).
            'date' => ['required', 'date_format:Y-m-d', Rule::unique('holidays', 'date')],
            // At least one name is required; HolidayService checks it.
            'name_en' => 'nullable|string|max:255',
            'name_bn' => 'nullable|string|max:255',
        ];
    }
}
