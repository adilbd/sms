<?php

namespace App\Http\Requests\FeeHead;

use App\Http\Requests\FeeHead\Concerns\NormalizesFeeHeadCode;
use App\Models\FeeHead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFeeHeadRequest extends FormRequest
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
            'name_en' => 'sometimes|nullable|string|max:255',
            'name_bn' => 'sometimes|nullable|string|max:255',
            'code' => ['sometimes', 'string', 'max:30', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('fee_heads', 'code')->ignore($this->route('fee_head'))],
            'kind' => ['sometimes', 'string', Rule::in(FeeHead::KINDS)],
            'is_active' => 'sometimes|boolean',
        ];
    }
}
