<?php

namespace App\Http\Requests\AcademicYear;

use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAcademicYearRequest extends FormRequest
{
    // Access is enforced by the permission middleware in AcademicYearController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year' => [
                'sometimes', 'integer',
                'min:'.AcademicYear::MIN_YEAR, 'max:'.AcademicYear::MAX_YEAR,
                Rule::unique('academic_years', 'year')->ignore($this->route('academic_year')),
            ],
            'name' => 'sometimes|string|max:255',
            'code' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('academic_years', 'code')->ignore($this->route('academic_year'))],
            'start_date' => 'sometimes|nullable|date',
            'end_date' => 'sometimes|nullable|date',
            'description' => 'nullable|string',
        ];
    }
}
