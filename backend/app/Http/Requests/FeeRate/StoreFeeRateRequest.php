<?php

namespace App\Http\Requests\FeeRate;

use App\Support\AcademicGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeeRateRequest extends FormRequest
{
    // Access is enforced by the permission middleware in FeeRateController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fee_head_id' => ['required', 'integer', Rule::exists('fee_heads', 'id')->whereNull('deleted_at')],
            'class_id' => ['required', 'integer', Rule::exists('classes', 'id')->whereNull('deleted_at')],
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->whereNull('deleted_at')],
            // Whether a group is allowed for this class (Class 9+) is a domain rule checked
            // in FeeRateService, not here.
            'group' => ['nullable', 'string', Rule::in(AcademicGroup::VALUES)],
            'amount' => 'required|numeric|decimal:0,2|min:0|max:99999999.99',
            'due_day' => 'nullable|integer|min:1|max:28',
        ];
    }
}
