<?php

namespace App\Http\Requests\AdmissionApplication;

use App\Models\AdmissionApplication;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexAdmissionApplicationRequest extends FormRequest
{
    // Access is enforced by the permission middleware in AdmissionApplicationController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'round_id' => 'sometimes|nullable|integer|min:1|max:4294967295',
            'class_id' => 'sometimes|nullable|integer|min:1|max:4294967295',
            'status' => ['sometimes', 'nullable', Rule::in(AdmissionApplication::STATUSES)],
            'search' => 'sometimes|nullable|string|max:255',
            'per_page' => 'sometimes|nullable|integer',
        ];
    }
}
