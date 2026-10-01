<?php

namespace App\Http\Requests\FeeWaiver;

use Illuminate\Foundation\Http\FormRequest;

class IndexFeeWaiverRequest extends FormRequest
{
    // Access is enforced by the permission middleware in FeeWaiverController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => 'sometimes|nullable|integer|min:1',
            'academic_year_id' => 'sometimes|nullable|integer|min:1',
            'fee_head_id' => 'sometimes|nullable|integer|min:1',
            'per_page' => 'sometimes|nullable|integer|min:1|max:100',
        ];
    }
}
