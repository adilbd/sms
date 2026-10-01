<?php

namespace App\Http\Requests\Exam;

use App\Http\Requests\Exam\Concerns\NormalizesExamCode;
use App\Models\Exam;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateExamRequest extends FormRequest
{
    use NormalizesExamCode;

    // Access is enforced by the permission middleware in ExamController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Exam $exam */
        $exam = $this->route('exam');

        return [
            // The exam's year and status are what identify it; neither changes here (status
            // moves through the open-marks-entry action).
            'academic_year_id' => 'prohibited',
            'status' => 'prohibited',
            'name_en' => 'sometimes|nullable|string|max:255',
            'name_bn' => 'sometimes|nullable|string|max:255',
            'code' => [
                'sometimes', 'string', 'max:50',
                Rule::unique('exams', 'code')->where('academic_year_id', $exam->academic_year_id)->ignore($exam),
            ],
            'type' => ['sometimes', 'string', Rule::in(Exam::TYPES)],
            'start_date' => 'sometimes|date_format:Y-m-d',
            'end_date' => 'sometimes|date_format:Y-m-d',
            'class_ids' => 'sometimes|array|min:1|max:12',
            'class_ids.*' => ['integer', 'distinct', Rule::exists('classes', 'id')->whereNull('deleted_at')],
        ];
    }
}
