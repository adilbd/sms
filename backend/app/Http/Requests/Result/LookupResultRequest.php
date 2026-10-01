<?php

namespace App\Http\Requests\Result;

use App\Support\AcademicGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The public result lookup (website form and /api/public/results): a published exam, the
 * student's date of birth, and either the student ID or the section, group and roll. Whether
 * the group fits the section's class depends on stored data, so ResultService checks it.
 * `language`, `page` and `orientation` only shape the website's marksheet.
 */
class LookupResultRequest extends FormRequest
{
    public const LANGUAGES = ['bn', 'en'];

    public const PAGES = ['a4', 'legal'];

    public const ORIENTATIONS = ['portrait', 'landscape'];

    public function authorize(): bool
    {
        // Public by design: the date of birth, the throttle and the identical "no result"
        // answer protect it, not a login.
        return true;
    }

    public function rules(): array
    {
        return [
            'exam_id' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'date_of_birth' => ['required', 'date_format:Y-m-d'],
            'student_id' => ['required_without_all:section_id,roll', 'nullable', 'string', 'max:30'],
            'section_id' => ['required_without:student_id', 'nullable', 'integer', 'min:1', 'max:4294967295'],
            'group' => ['nullable', 'string', Rule::in(AcademicGroup::VALUES)],
            'roll' => ['required_without:student_id', 'nullable', 'integer', 'min:1', 'max:99999'],
            'language' => ['sometimes', 'nullable', Rule::in(self::LANGUAGES)],
            'page' => ['sometimes', 'nullable', Rule::in(self::PAGES)],
            'orientation' => ['sometimes', 'nullable', Rule::in(self::ORIENTATIONS)],
        ];
    }
}
