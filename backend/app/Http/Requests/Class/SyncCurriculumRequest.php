<?php

namespace App\Http\Requests\Class;

use App\Models\ClassSubject;
use App\Support\AcademicGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncCurriculumRequest extends FormRequest
{
    // Access is enforced by the permission middleware in ClassController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The rules that depend on the class (groups and optional rows from Class 9),
            // on duplicates and on which subjects are active live in CurriculumService.
            'subjects' => ['present', 'array', 'max:60'],
            'subjects.*.subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->whereNull('deleted_at')],
            'subjects.*.group' => ['nullable', 'string', Rule::in(AcademicGroup::VALUES)],
            'subjects.*.type' => ['required', 'string', Rule::in(ClassSubject::TYPES)],
            // The marks scheme. Omitting every part keeps a row's saved marks (a new row
            // gets its subject's defaults); the rules between the fields (at least one
            // part, full with pass, pass <= full, paper pairs) are in CurriculumService.
            'subjects.*.written_full' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'subjects.*.written_pass' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'subjects.*.mcq_full' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'subjects.*.mcq_pass' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'subjects.*.practical_full' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'subjects.*.practical_pass' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
            'subjects.*.paper_group' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[a-z0-9-]+$/'],
        ];
    }
}
