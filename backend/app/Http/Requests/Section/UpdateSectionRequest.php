<?php

namespace App\Http\Requests\Section;

use App\Support\AcademicGroup;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSectionRequest extends FormRequest
{
    // Access is enforced by the permission middleware in SectionController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $section = $this->route('section');
        $shiftId = $this->input('shift_id', $section?->shift_id);

        return [
            // A section's class can't change once created (see CLAUDE.md).
            'class_id' => 'prohibited',
            'shift_id' => [
                'sometimes', 'integer',
                Rule::exists('shifts', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'name' => 'sometimes|string|max:255',
            'code' => [
                'sometimes', 'string', 'max:255',
                Rule::unique('sections', 'code')->where(fn ($query) => $query
                    ->where('class_id', $section?->class_id)
                    ->where('shift_id', $shiftId))
                    ->ignore($section?->id),
            ],
            'capacity' => 'sometimes|integer|min:1|max:200',
            'group' => ['sometimes', 'nullable', 'string', Rule::in(AcademicGroup::VALUES)],
            'description' => 'nullable|string',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
