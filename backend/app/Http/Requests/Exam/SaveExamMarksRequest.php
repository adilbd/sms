<?php

namespace App\Http\Requests\Exam;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveExamMarksRequest extends FormRequest
{
    // Access is enforced by the permission middleware in ExamMarkController; who may save
    // which sheet (the assigned teacher or an admin) is checked in ExamMarkService.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // At most two decimals, like the decimal(5,2) columns. Each part's own full mark
        // and whether the subject has the part at all are checked in ExamMarkService.
        $part = ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999.99', 'decimal:0,2'];

        return [
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->whereNull('deleted_at')],
            'exam_subject_id' => ['required', 'integer', 'exists:exam_subjects,id'],
            'marks' => ['required', 'array', 'min:1', 'max:200'],
            'marks.*.student_id' => ['required', 'integer', 'distinct'],
            'marks.*.written' => $part,
            'marks.*.mcq' => $part,
            'marks.*.practical' => $part,
            'marks.*.is_absent' => 'sometimes|boolean',
        ];
    }
}
