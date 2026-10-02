<?php

namespace App\Http\Requests\Certificate;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Exactly one of `section_id` and `student_id`.
 */
class IdCardRequest extends FormRequest
{
    // Access is enforced by the permission middleware in IdCardController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section_id' => ['required_without:student_id', 'prohibits:student_id', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'student_id' => ['required_without:section_id', 'prohibits:section_id', 'nullable', 'integer', 'min:1', 'max:4294967295'],
        ];
    }
}
