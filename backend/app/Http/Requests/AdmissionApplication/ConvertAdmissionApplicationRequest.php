<?php

namespace App\Http\Requests\AdmissionApplication;

use App\Support\AcademicGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The enrolment choices and passwords for the new student. Whether the section fits the
 * class, and the group, 4th subject and seat rules, are checked by the service and
 * EnrolmentService (their errors keep the `enrolment.*` keys of the student form).
 */
class ConvertAdmissionApplicationRequest extends FormRequest
{
    // Access is enforced by the permission middleware in AdmissionApplicationController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->whereNull('deleted_at')],
            'roll_number' => 'nullable|integer|min:1|max:65535',
            'group' => ['nullable', Rule::in(AcademicGroup::VALUES)],
            'optional_subject_id' => ['nullable', 'integer', Rule::exists('subjects', 'id')->whereNull('deleted_at')],
            'password' => 'required|string|min:8|max:255',
            'guardian_password' => 'nullable|string|min:8|max:255',
        ];
    }
}
