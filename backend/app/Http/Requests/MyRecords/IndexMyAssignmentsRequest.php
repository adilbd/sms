<?php

namespace App\Http\Requests\MyRecords;

use Illuminate\Foundation\Http\FormRequest;

class IndexMyAssignmentsRequest extends FormRequest
{
    // Access is enforced by the role:teacher middleware on the route.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => 'sometimes|nullable|integer|exists:academic_years,id',
        ];
    }
}
