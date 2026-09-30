<?php

namespace App\Http\Requests\AcademicYear;

use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;

class StoreAcademicYearRequest extends FormRequest
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
                'required', 'integer',
                'min:'.AcademicYear::MIN_YEAR, 'max:'.AcademicYear::MAX_YEAR,
                'unique:academic_years,year',
            ],
            'name' => 'required|string|max:255',
            // Defaults to the year, and to Jan 1 / Dec 31 of that year, in
            // AcademicYearService::create(). Bounds are checked there too, since
            // they depend on `year`.
            'code' => 'nullable|string|max:255|unique:academic_years,code',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'description' => 'nullable|string',
        ];
    }
}
