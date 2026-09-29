<?php

namespace App\Http\Requests\Staff;

use App\Models\Staff;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'sometimes|nullable|string|max:100',
            'category' => ['sometimes', 'nullable', Rule::in(Staff::CATEGORIES)],
            'position' => ['sometimes', 'nullable', Rule::in(Staff::POSITIONS)],
            // Query strings arrive as text, so accept the common boolean spellings.
            'is_active' => 'sometimes|nullable|in:true,false,1,0',
            'shift' => 'sometimes|nullable|integer|exists:shifts,id',
        ];
    }
}
