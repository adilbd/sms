<?php

namespace App\Http\Requests\MyRecords;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `?student=` picks a guardian's child (ignored for a student); `?academic_year_id=` picks a
 * teacher's year (the active one when omitted).
 */
class ShowMyRoutineRequest extends FormRequest
{
    // Access is enforced by the role middleware on the route; whose record may be read is
    // checked in PortalService::resolveStudent().
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'academic_year_id' => ['sometimes', 'nullable', 'integer', Rule::exists('academic_years', 'id')->whereNull('deleted_at')],
        ];
    }

    public function studentId(): ?int
    {
        return filled($this->validated('student')) ? (int) $this->validated('student') : null;
    }

    public function yearId(): ?int
    {
        return filled($this->validated('academic_year_id')) ? (int) $this->validated('academic_year_id') : null;
    }
}
