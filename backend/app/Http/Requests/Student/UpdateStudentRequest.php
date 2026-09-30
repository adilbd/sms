<?php

namespace App\Http\Requests\Student;

use App\Http\Requests\Student\Concerns\HasStudentRules;
use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    use HasStudentRules;

    // Access is enforced by the permission middleware in StudentController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $student = $this->route('student');

        // Every shared rule becomes optional on update. A value that is sent still has
        // to be valid: dropping `required` does not make null acceptable for the
        // non-nullable fields (gender, guardian_*), since they carry no `nullable`.
        $rules = [];
        foreach ($this->sharedProfileRules() as $field => $rule) {
            $parts = is_array($rule) ? $rule : explode('|', $rule);
            $rules[$field] = array_merge(['sometimes'], array_values(array_diff($parts, ['required'])));
        }

        return array_merge($rules, [
            'date_of_birth' => 'sometimes|date|before_or_equal:today',
            'birth_registration_number' => ['sometimes', 'nullable', 'digits:17', Rule::unique('students', 'birth_registration_number')->ignore($student)],
            'nationality' => 'sometimes|string|max:100',
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($student instanceof Student ? $student->user_id : null)],
            'admission_date' => 'sometimes|date',
            'status' => ['sometimes', Rule::in(Student::STATUSES)],
            'password' => 'sometimes|nullable|string|min:8|max:255',
            'remove_photo' => 'sometimes|boolean',
        ], $this->enrolmentRules(required: false));
    }
}
