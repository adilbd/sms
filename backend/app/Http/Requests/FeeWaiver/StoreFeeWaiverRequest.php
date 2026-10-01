<?php

namespace App\Http\Requests\FeeWaiver;

use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeeWaiverRequest extends FormRequest
{
    // Access is enforced by the permission middleware in FeeWaiverController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->whereNull('deleted_at')],
            'fee_head_id' => ['required', 'integer', Rule::exists('fee_heads', 'id')->whereNull('deleted_at')],
            // Exactly one of the two is required; FeeWaiverService checks it.
            'percent' => 'nullable|numeric|decimal:0,2|regex:'.Money::MONEY_PATTERN.'|min:0|max:100',
            'fixed_amount' => 'nullable|numeric|decimal:0,2|regex:'.Money::MONEY_PATTERN.'|min:0|max:99999999.99',
            'reason' => 'nullable|string|max:255',
        ];
    }
}
