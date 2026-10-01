<?php

namespace App\Http\Requests\Exam;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexExamMarkRequest extends FormRequest
{
    // Access is enforced by the permission middleware in ExamMarkController; who may read
    // which sheet (the assigned teacher or an admin) is checked in ExamMarkService.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->whereNull('deleted_at')],
            'exam_subject_id' => ['required', 'integer', 'exists:exam_subjects,id'],
        ];
    }
}
