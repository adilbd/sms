<?php

namespace App\Http\Requests\Promotion;

use App\Support\AcademicGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ApplyPromotionRequest extends FormRequest
{
    // Access is enforced by the permission middleware in PromotionController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_academic_year_id' => 'required|integer|exists:academic_years,id',
            'to_academic_year_id' => 'required|integer|exists:academic_years,id',
            'section_id' => 'required|integer|exists:sections,id',
            'class_id' => 'sometimes|nullable|integer|exists:classes,id',
            // Required unless the source class is 12 or nobody is promoted: PromotionService decides.
            'default_target_section_id' => 'sometimes|nullable|integer|exists:sections,id',
            'exceptions' => 'sometimes|array|max:500',
            'exceptions.*.student_id' => 'required|integer|exists:students,id',
            'exceptions.*.action' => ['required', Rule::in(['promote', 'retain', 'leave', 'skip'])],
            'exceptions.*.target_section_id' => 'sometimes|nullable|integer|exists:sections,id',
            'exceptions.*.group' => ['sometimes', 'nullable', Rule::in(AcademicGroup::VALUES)],
            // An explicit null means "no 4th subject", so the key's presence is kept.
            'exceptions.*.optional_subject_id' => 'sometimes|nullable|integer|exists:subjects,id',
        ];
    }
}
