<?php

namespace App\Http\Requests\Subject;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', Rule::unique('subjects', 'code')->ignore($this->route('subject'))],
            'type' => 'nullable|string',
            'total_marks' => 'nullable|integer',
            'pass_marks' => 'nullable|integer',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ];
    }
}
