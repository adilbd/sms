<?php

namespace App\Http\Requests\Section;

use App\Support\AcademicGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'sometimes|nullable|string|max:100',
            'class_id' => 'sometimes|nullable|integer|exists:classes,id',
            'shift_id' => 'sometimes|nullable|integer|exists:shifts,id',
            'group' => ['sometimes', 'nullable', 'string', Rule::in(AcademicGroup::VALUES)],
            'is_active' => 'sometimes|nullable|in:true,false,1,0',
        ];
    }
}
