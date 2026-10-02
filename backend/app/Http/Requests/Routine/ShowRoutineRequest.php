<?php

namespace App\Http\Requests\Routine;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The optional `?academic_year_id=` of a routine read (the active year when absent).
 */
class ShowRoutineRequest extends FormRequest
{
    // Access is enforced by the permission middleware in RoutineController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['sometimes', 'nullable', 'integer', Rule::exists('academic_years', 'id')->whereNull('deleted_at')],
        ];
    }

    public function yearId(): ?int
    {
        return filled($this->validated('academic_year_id')) ? (int) $this->validated('academic_year_id') : null;
    }
}
