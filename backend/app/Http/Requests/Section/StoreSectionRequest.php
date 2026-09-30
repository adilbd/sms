<?php

namespace App\Http\Requests\Section;

use App\Http\Requests\Section\Concerns\NormalizesSectionCode;
use App\Support\AcademicGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSectionRequest extends FormRequest
{
    use NormalizesSectionCode;

    // Access is enforced by the permission middleware in SectionController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'class_id' => 'required|integer|exists:classes,id',
            'shift_id' => [
                'required', 'integer',
                Rule::exists('shifts', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'name' => 'required|string|max:255',
            'code' => [
                'required', 'string', 'max:255',
                Rule::unique('sections', 'code')->where(fn ($query) => $query
                    ->where('class_id', $this->input('class_id'))
                    ->where('shift_id', $this->input('shift_id'))),
            ],
            'capacity' => 'sometimes|integer|min:1|max:200',
            // Whether a group is actually allowed for this class (Class 9+) is a
            // domain rule checked in SectionService, not here (see CLAUDE.md).
            'group' => ['nullable', 'string', Rule::in(AcademicGroup::VALUES)],
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
