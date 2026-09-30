<?php

namespace App\Http\Requests\Class;

use App\Support\AcademicGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCurriculumRequest extends FormRequest
{
    // Access is enforced by the permission middleware in ClassController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group' => ['sometimes', 'nullable', 'string', Rule::in(AcademicGroup::VALUES)],
        ];
    }
}
