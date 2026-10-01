<?php

namespace App\Http\Requests\Exam;

use Illuminate\Foundation\Http\FormRequest;

class UpdateExamSubjectRequest extends FormRequest
{
    // Access is enforced by the permission middleware in ExamSubjectController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $mark = 'sometimes|nullable|integer|min:0|max:1000';

        return [
            // Asia/Dhaka wall-clock time, like shifts (see CLAUDE.md).
            'exam_date' => 'sometimes|nullable|date_format:Y-m-d',
            'start_time' => 'sometimes|nullable|date_format:H:i',
            'end_time' => 'sometimes|nullable|date_format:H:i',
            // The part rules (at least one part, full and pass together, pass <= full) and
            // the 409 once marks exist live in ExamScheduleService.
            'written_full' => $mark,
            'written_pass' => $mark,
            'mcq_full' => $mark,
            'mcq_pass' => $mark,
            'practical_full' => $mark,
            'practical_pass' => $mark,
        ];
    }
}
