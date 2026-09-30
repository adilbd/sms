<?php

namespace App\Http\Requests\Student\Concerns;

use App\Models\Student;
use App\Support\AcademicGroup;
use App\Support\Mobile;
use Illuminate\Validation\Rule;

/**
 * Field rules shared by the store and update requests. Mobile numbers are normalized to
 * 01XXXXXXXXX in prepareForValidation() (a "+88" prefix is dropped) before the pattern
 * rule runs.
 */
trait HasStudentRules
{
    /** @var list<string> */
    private array $mobileFields = ['mobile', 'father_mobile', 'mother_mobile', 'guardian_mobile'];

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach ($this->mobileFields as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = Mobile::normalize($this->input($field));
            }
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    protected function mobileRule(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'regex:'.Mobile::PATTERN];
    }

    /**
     * The `enrolment` block for the active academic year. Whether the group and 4th
     * subject are allowed or required depends on the section's class and is checked in
     * EnrolmentService, keyed to these same field names.
     *
     * @return array<string, mixed>
     */
    protected function enrolmentRules(bool $required): array
    {
        return [
            'enrolment' => [$required ? 'required' : 'sometimes', 'array'],
            'enrolment.section_id' => ['required_with:enrolment', 'integer', Rule::exists('sections', 'id')->whereNull('deleted_at')],
            'enrolment.group' => ['nullable', Rule::in(AcademicGroup::VALUES)],
            'enrolment.optional_subject_id' => ['nullable', 'integer', Rule::exists('subjects', 'id')->whereNull('deleted_at')],
            'enrolment.roll_number' => 'nullable|integer|min:1|max:65535',
        ];
    }

    /**
     * Fields that are the same on store and update apart from `sometimes`/required.
     *
     * @return array<string, mixed>
     */
    protected function sharedProfileRules(): array
    {
        return [
            'name_en' => 'nullable|string|max:255',
            'name_bn' => 'nullable|string|max:255',
            'gender' => ['required', Rule::in(Student::GENDERS)],
            'religion' => ['nullable', Rule::in(Student::RELIGIONS)],
            'blood_group' => ['nullable', Rule::in(Student::BLOOD_GROUPS)],
            'mobile' => $this->mobileRule(),
            'present_address' => 'nullable|string|max:1000',
            'permanent_address' => 'nullable|string|max:1000',
            'district' => 'nullable|string|max:100',
            'leaving_date' => 'nullable|date',
            'father_name_en' => 'nullable|string|max:255',
            'father_name_bn' => 'nullable|string|max:255',
            'father_mobile' => $this->mobileRule(),
            'father_occupation' => 'nullable|string|max:255',
            'mother_name_en' => 'nullable|string|max:255',
            'mother_name_bn' => 'nullable|string|max:255',
            'mother_mobile' => $this->mobileRule(),
            'mother_occupation' => 'nullable|string|max:255',
            'guardian_relation' => ['required', Rule::in(Student::GUARDIAN_RELATIONS)],
            'guardian_name' => 'required|string|max:255',
            'guardian_mobile' => $this->mobileRule(required: true),
            'guardian_password' => 'nullable|string|min:8|max:255',
            // SVG is excluded: it can carry script content (same as post-body uploads).
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ];
    }
}
