<?php

namespace App\Http\Requests\Exam;

use Illuminate\Foundation\Http\FormRequest;

class IndexExamSubjectRequest extends FormRequest
{
    // Access is enforced by the permission middleware in ExamSubjectController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'class_id' => 'sometimes|nullable|integer|min:1',
        ];
    }
}
