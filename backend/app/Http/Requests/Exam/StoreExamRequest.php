<?php

namespace App\Http\Requests\Exam;

use App\Http\Requests\Exam\Concerns\NormalizesExamCode;
use App\Models\Exam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExamRequest extends FormRequest
{
    use NormalizesExamCode;

    // Access is enforced by the permission middleware in ExamController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->whereNull('deleted_at')],
            // At least one of the two names is required: checked in ExamService.
            'name_en' => 'sometimes|nullable|string|max:255',
            'name_bn' => 'sometimes|nullable|string|max:255',
            // The index also covers soft-deleted exams, so the rule does too.
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('exams', 'code')->where('academic_year_id', $this->input('academic_year_id')),
            ],
            'type' => ['required', 'string', Rule::in(Exam::TYPES)],
            // The year window and end >= start are checked in ExamService.
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d',
            'class_ids' => 'required|array|min:1|max:12',
            'class_ids.*' => ['integer', 'distinct', Rule::exists('classes', 'id')->whereNull('deleted_at')],
        ];
    }
}
