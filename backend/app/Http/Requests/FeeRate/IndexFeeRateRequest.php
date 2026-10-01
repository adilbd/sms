<?php

namespace App\Http\Requests\FeeRate;

use Illuminate\Foundation\Http\FormRequest;

class IndexFeeRateRequest extends FormRequest
{
    // Access is enforced by the permission middleware in FeeRateController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => 'sometimes|nullable|integer|min:1',
            'class_id' => 'sometimes|nullable|integer|min:1',
            'fee_head_id' => 'sometimes|nullable|integer|min:1',
            'per_page' => 'sometimes|nullable|integer|min:1|max:100',
        ];
    }
}
