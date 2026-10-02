<?php

namespace App\Http\Requests\Period;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePeriodRequest extends FormRequest
{
    // Access is enforced by the permission middleware in PeriodController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $period = $this->route('period');

        return [
            // A period stays in its shift; create a new one instead.
            'shift_id' => 'prohibited',
            'number' => [
                'sometimes', 'integer', 'min:1', 'max:99',
                Rule::unique('periods', 'number')->where('shift_id', $period?->shift_id)->ignore($period),
            ],
            'name_en' => 'sometimes|string|max:255',
            'name_bn' => 'sometimes|string|max:255',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i',
            'is_break' => 'sometimes|boolean',
        ];
    }
}
