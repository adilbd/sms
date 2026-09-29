<?php

namespace App\Http\Requests\Staff\Concerns;

use Illuminate\Validation\Rule;

/**
 * Validation rules for the "shift_ids", "educations" and "trainings" arrays carried by
 * the staff store/update payload (see StaffService and StaffRepository::syncChildRows()).
 * Shared by Store/UpdateStaffRequest.
 */
trait HasStaffChildRules
{
    protected function shiftRules(bool $required): array
    {
        return [
            'shift_ids' => [$required ? 'required' : 'sometimes', 'array', 'min:1'],
            'shift_ids.*' => ['integer', Rule::exists('shifts', 'id')->where(fn ($q) => $q->where('is_active', true))],
        ];
    }

    protected function educationRules(): array
    {
        return [
            // Capped well above any real career so a malicious or buggy client can't
            // force the sync transaction to process an unbounded number of rows.
            'educations' => 'sometimes|array|max:50',
            'educations.*.id' => 'sometimes|nullable|integer',
            'educations.*.degree' => 'required|string|max:255',
            'educations.*.institution' => 'nullable|string|max:255',
            'educations.*.board_university' => 'nullable|string|max:255',
            'educations.*.passing_year' => 'nullable|string|max:20',
            'educations.*.result' => 'nullable|string|max:100',
        ];
    }

    protected function trainingRules(): array
    {
        return [
            'trainings' => 'sometimes|array|max:50',
            'trainings.*.id' => 'sometimes|nullable|integer',
            'trainings.*.title' => 'required|string|max:255',
            'trainings.*.organizer' => 'nullable|string|max:255',
            'trainings.*.duration' => 'nullable|string|max:100',
            'trainings.*.year' => 'nullable|string|max:20',
        ];
    }
}
