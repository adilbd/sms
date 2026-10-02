<?php

namespace App\Http\Requests\Certificate;

use App\Models\Certificate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Field-level rules only. Whether the student can be issued this type (active, enrolled,
 * no outstanding fees, one TC) is checked in CertificateService. All text is plain text.
 */
class StoreCertificateRequest extends FormRequest
{
    // Access is enforced by the permission middleware in CertificateController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = $this->input('type');

        return [
            'type' => ['required', 'string', Rule::in(Certificate::TYPES)],
            'student_id' => ['required', 'integer', Rule::exists('students', 'id')->whereNull('deleted_at')],
            'conduct' => ['sometimes', 'nullable', 'string', 'max:500'],
            'remarks' => ['sometimes', 'nullable', 'string', 'max:500'],

            // Testimonial
            'exam' => ['sometimes', 'nullable', Rule::in(['ssc', 'hsc'])],
            'exam_roll' => ['sometimes', 'nullable', 'string', 'max:50'],
            'registration_no' => ['sometimes', 'nullable', 'string', 'max:50'],
            'board' => ['sometimes', 'nullable', 'string', 'max:100'],
            'passing_year' => ['sometimes', 'nullable', 'integer', 'min:1950', 'max:2100'],
            'gpa' => ['sometimes', 'nullable', 'numeric', 'between:0,5', 'regex:/^\d(\.\d{1,2})?$/'],
            'session' => ['sometimes', 'nullable', 'string', 'max:20'],

            // Transfer
            'reason' => [Rule::requiredIf($type === Certificate::TYPE_TRANSFER), 'nullable', 'string', 'max:500'],
            'allow_outstanding' => ['sometimes', 'boolean'],
            'outstanding_note' => ['nullable', 'string', 'max:500', Rule::requiredIf($this->boolean('allow_outstanding'))],
        ];
    }

    protected function prepareForValidation(): void
    {
        $trim = [];

        foreach (['conduct', 'remarks', 'exam_roll', 'registration_no', 'board', 'session', 'reason', 'outstanding_note'] as $field) {
            if (is_string($this->input($field))) {
                $trim[$field] = trim($this->input($field));
            }
        }

        $this->merge($trim);
    }
}
