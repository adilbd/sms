<?php

namespace App\Http\Requests\AdmissionRound;

use Illuminate\Foundation\Http\FormRequest;

class IndexAdmissionRoundRequest extends FormRequest
{
    // Access is enforced by the permission middleware in AdmissionRoundController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => 'sometimes|nullable|integer|min:1|max:4294967295',
            'is_published' => 'sometimes|nullable|boolean',
            'search' => 'sometimes|nullable|string|max:255',
            'per_page' => 'sometimes|nullable|integer',
        ];
    }
}
