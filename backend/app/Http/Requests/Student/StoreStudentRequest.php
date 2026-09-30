<?php

namespace App\Http\Requests\Student;

use App\Http\Requests\Student\Concerns\HasStudentRules;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentRequest extends FormRequest
{
    use HasStudentRules;

    // Access is enforced by the permission middleware in StudentController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge($this->sharedProfileRules(), [
            // At least one of name_en/name_bn is required, but that's checked in
            // StudentService against the model with the input applied (the same pattern
            // as StaffService), not here.
            'date_of_birth' => 'required|date|before_or_equal:today',
            'birth_registration_number' => ['nullable', 'digits:17', Rule::unique('students', 'birth_registration_number')],
            'nationality' => 'sometimes|string|max:100',
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')],
            'admission_date' => 'required|date',
            'status' => ['sometimes', Rule::in(Student::STATUSES)],
            'password' => 'required|string|min:8|max:255',
        ], $this->enrolmentRules(required: true));
    }
}
