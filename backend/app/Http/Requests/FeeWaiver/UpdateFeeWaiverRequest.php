<?php

namespace App\Http\Requests\FeeWaiver;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeeWaiverRequest extends FormRequest
{
    // Access is enforced by the permission middleware in FeeWaiverController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A waiver's student, year and head are fixed once it exists.
            'student_id' => 'prohibited',
            'academic_year_id' => 'prohibited',
            'fee_head_id' => 'prohibited',
            'percent' => 'sometimes|nullable|numeric|decimal:0,2|regex:/^\d+(\.\d{1,2})?$/|min:0|max:100',
            'fixed_amount' => 'sometimes|nullable|numeric|decimal:0,2|regex:/^\d+(\.\d{1,2})?$/|min:0|max:99999999.99',
            'reason' => 'sometimes|nullable|string|max:255',
        ];
    }
}
