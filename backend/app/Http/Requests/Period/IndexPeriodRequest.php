<?php

namespace App\Http\Requests\Period;

use Illuminate\Foundation\Http\FormRequest;

class IndexPeriodRequest extends FormRequest
{
    // Access is enforced by the permission middleware in PeriodController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shift_id' => 'sometimes|nullable|integer|min:1|max:4294967295',
        ];
    }
}
