<?php

namespace App\Http\Requests\Subject;

use App\Http\Requests\Subject\Concerns\NormalizesSubjectCode;
use App\Models\Subject;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSubjectRequest extends FormRequest
{
    use NormalizesSubjectCode;

    // Access is enforced by the permission middleware in SubjectController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'string', 'max:255', Rule::unique('subjects', 'code')->ignore($this->route('subject'))],
            'type' => ['sometimes', 'string', Rule::in(Subject::TYPES)],
            'total_marks' => 'sometimes|integer|min:1|max:1000',
            'pass_marks' => 'sometimes|integer|min:0|max:1000',
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
