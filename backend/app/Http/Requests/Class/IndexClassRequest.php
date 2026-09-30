<?php

namespace App\Http\Requests\Class;

use App\Models\Classes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'sometimes|nullable|string|max:100',
            'is_active' => 'sometimes|nullable|in:true,false,1,0',
            'level' => ['sometimes', 'nullable', 'string', Rule::in(Classes::LEVELS)],
        ];
    }
}
