<?php

namespace App\Http\Requests\FeeHead;

use App\Http\Requests\FeeHead\Concerns\NormalizesFeeHeadCode;
use App\Models\FeeHead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeeHeadRequest extends FormRequest
{
    use NormalizesFeeHeadCode;

    // Access is enforced by the permission middleware in FeeHeadController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // At least one name is required; FeeHeadService checks it.
            'name_en' => 'nullable|string|max:255',
            'name_bn' => 'nullable|string|max:255',
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('fee_heads', 'code')],
            'kind' => ['required', 'string', Rule::in(FeeHead::KINDS)],
            'is_active' => 'sometimes|boolean',
        ];
    }
}
