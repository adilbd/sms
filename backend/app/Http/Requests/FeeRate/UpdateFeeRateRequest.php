<?php

namespace App\Http\Requests\FeeRate;

use App\Support\AcademicGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeeRateRequest extends FormRequest
{
    // Access is enforced by the permission middleware in FeeRateController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A rate's head, class and year are fixed once it exists: delete and recreate it.
            'fee_head_id' => 'prohibited',
            'class_id' => 'prohibited',
            'academic_year_id' => 'prohibited',
            'group' => ['sometimes', 'nullable', 'string', Rule::in(AcademicGroup::VALUES)],
            'amount' => 'sometimes|numeric|decimal:0,2|regex:/^\d+(\.\d{1,2})?$/|min:0|max:99999999.99',
            'due_day' => 'sometimes|nullable|integer|min:1|max:28',
        ];
    }
}
