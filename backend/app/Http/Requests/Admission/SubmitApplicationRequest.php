<?php

namespace App\Http\Requests\Admission;

use App\Models\Student;
use App\Support\AcademicGroup;
use App\Support\Mobile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The public application form. Only the student photo is required among the files; the
 * birth certificate and the previous school's TC or report are optional. Mobile numbers
 * are normalized to 01XXXXXXXXX before the pattern rule runs. Whether the class is
 * offered in the round, the group rule from Class 9 and the one-application-per-child
 * rule depend on stored data, so AdmissionService checks them.
 *
 * `website` is the honeypot: real visitors never see it, bots fill it, and a filled one
 * fails validation.
 */
class SubmitApplicationRequest extends FormRequest
{
    /** @var list<string> */
    private array $mobileFields = ['father_mobile', 'mother_mobile', 'guardian_mobile'];

    // Public by design: the CSRF token, the honeypot and the throttle protect it.
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach ($this->mobileFields as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = Mobile::normalize($this->input($field));
            }
        }

        if (is_string($this->input('guardian_email'))) {
            $normalized['guardian_email'] = mb_strtolower(trim($this->input('guardian_email')));
        }

        if (is_string($this->input('birth_registration_number'))) {
            $normalized['birth_registration_number'] = preg_replace('/\s+/', '', $this->input('birth_registration_number'));
        }

        $this->merge($normalized);
    }

    public function rules(): array
    {
        return [
            'website' => ['prohibited'],
            'class_id' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'group' => ['nullable', Rule::in(AcademicGroup::VALUES)],
            'shift_id' => ['nullable', 'integer', Rule::exists('shifts', 'id')->where('is_active', true)],

            // At least one name is required (both arrive in the same request, so no saved
            // state is involved).
            'name_en' => ['nullable', 'required_without:name_bn', 'string', 'max:255'],
            'name_bn' => ['nullable', 'required_without:name_en', 'string', 'max:255'],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', 'after:1990-01-01'],
            'gender' => ['required', Rule::in(Student::GENDERS)],
            'religion' => ['nullable', Rule::in(Student::RELIGIONS)],
            'birth_registration_number' => ['required', 'digits:17'],
            'blood_group' => ['nullable', Rule::in(Student::BLOOD_GROUPS)],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:100'],

            'previous_school' => 'nullable|string|max:255',
            'previous_class' => 'nullable|string|max:100',

            'father_name_en' => 'nullable|string|max:255',
            'father_name_bn' => 'nullable|string|max:255',
            'father_mobile' => ['nullable', 'string', 'regex:'.Mobile::PATTERN],
            'mother_name_en' => 'nullable|string|max:255',
            'mother_name_bn' => 'nullable|string|max:255',
            'mother_mobile' => ['nullable', 'string', 'regex:'.Mobile::PATTERN],
            'guardian_relation' => ['required', Rule::in(Student::GUARDIAN_RELATIONS)],
            'guardian_name' => 'required|string|max:255',
            'guardian_mobile' => ['required', 'string', 'regex:'.Mobile::PATTERN],
            'guardian_email' => 'nullable|email|max:255',

            'present_address' => 'required|string|max:1000',
            'permanent_address' => 'nullable|string|max:1000',
            'district' => 'nullable|string|max:100',

            // SVG is excluded: it can carry script content. Files are stored privately.
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:2048'],
            'birth_certificate' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
            'previous_school_doc' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'website.prohibited' => 'ফর্মটি জমা দেওয়া যায়নি। পাতাটি রিফ্রেশ করে আবার চেষ্টা করুন। (The form could not be submitted. Please reload the page and try again.)',
            'photo.required' => 'শিক্ষার্থীর ছবি দিতে হবে। (The student photo is required.)',
            'photo.max' => 'ছবির আকার সর্বোচ্চ ২ মেগাবাইট হতে পারে। (The photo may not be larger than 2 MB.)',
            'photo.mimes' => 'ছবি অবশ্যই JPG বা PNG হতে হবে। (The photo must be a JPG or PNG.)',
            'birth_certificate.max' => 'ফাইলের আকার সর্বোচ্চ ৫ মেগাবাইট হতে পারে। (The file may not be larger than 5 MB.)',
            'previous_school_doc.max' => 'ফাইলের আকার সর্বোচ্চ ৫ মেগাবাইট হতে পারে। (The file may not be larger than 5 MB.)',
            'birth_registration_number.digits' => 'জন্ম নিবন্ধন নম্বর ১৭ অঙ্কের হতে হবে। (The birth registration number must be 17 digits.)',
        ];
    }

    /**
     * The validated fields without the three uploads.
     *
     * @return array<string, mixed>
     */
    public function applicationData(): array
    {
        return $this->safe()->except(['website', 'photo', 'birth_certificate', 'previous_school_doc']);
    }
}
