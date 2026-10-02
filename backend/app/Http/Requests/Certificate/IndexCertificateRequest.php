<?php

namespace App\Http\Requests\Certificate;

use App\Models\Certificate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexCertificateRequest extends FormRequest
{
    // Access is enforced by the permission middleware in CertificateController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'nullable', 'string', Rule::in(Certificate::TYPES)],
            'student_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'academic_year_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['sometimes', 'nullable', 'string', Rule::in([Certificate::STATUS_ISSUED, Certificate::STATUS_CANCELLED])],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'per_page' => ['sometimes', 'nullable', 'integer'],
            'page' => ['sometimes', 'nullable', 'integer'],
        ];
    }
}
