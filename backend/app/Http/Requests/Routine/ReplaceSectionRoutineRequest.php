<?php

namespace App\Http\Requests\Routine;

use App\Models\RoutineSlot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReplaceSectionRoutineRequest extends FormRequest
{
    // Access is enforced by the permission middleware in RoutineController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')->whereNull('deleted_at')],
            // The whole grid; an empty list clears it. Weekly holidays, the section's shift,
            // breaks, the curriculum, the teacher's assignment and clashes are checked in
            // RoutineService, which has the saved state.
            'slots' => ['present', 'array', 'max:200'],
            'slots.*.day' => ['required', 'string', Rule::in(RoutineSlot::DAYS)],
            'slots.*.period_id' => ['required', 'integer', Rule::exists('periods', 'id')],
            'slots.*.subject_id' => ['required', 'integer', Rule::exists('subjects', 'id')->whereNull('deleted_at')],
            'slots.*.staff_id' => ['sometimes', 'nullable', 'integer', Rule::exists('staff', 'id')->whereNull('deleted_at')],
            'slots.*.room' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
