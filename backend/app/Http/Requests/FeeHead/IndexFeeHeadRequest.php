<?php

namespace App\Http\Requests\FeeHead;

use App\Models\FeeHead;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexFeeHeadRequest extends FormRequest
{
    // Access is enforced by the permission middleware in FeeHeadController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'sometimes|nullable|string|max:100',
            'kind' => ['sometimes', 'nullable', 'string', Rule::in(FeeHead::KINDS)],
            'is_active' => 'sometimes|nullable|in:true,false,1,0',
            'per_page' => 'sometimes|nullable|integer|min:1|max:100',
        ];
    }
}
