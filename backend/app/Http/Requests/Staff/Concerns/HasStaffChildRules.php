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
    /**
     * @param  list<int>  $alwaysAllowedShiftIds  Shift ids a member already has, so a
     *                                            shift deactivated after they were
     *                                            assigned to it doesn't turn every
     *                                            other, unrelated update to that
     *                                            member into a hidden 422 (see
     *                                            UpdateStaffRequest). A newly added
     *                                            shift still has to be active.
     */
    protected function shiftRules(bool $required, array $alwaysAllowedShiftIds = []): array
    {
        return [
            'shift_ids' => [$required ? 'required' : 'sometimes', 'array', 'min:1'],
            'shift_ids.*' => [
                'integer',
                Rule::exists('shifts', 'id')->where(
                    fn ($q) => $q->where('is_active', true)->when(
                        $alwaysAllowedShiftIds,
                        fn ($q) => $q->orWhereIn('id', $alwaysAllowedShiftIds)
                    )
                ),
            ],
        ];
    }

    protected function educationRules(): array
    {
        return [
            // Capped well above any real career so a malicious or buggy client can't
            // force the sync transaction to process an unbounded number of rows.
            // nullable: a multipart request can't express an empty array by omitting
            // the field (there would be nothing to distinguish that from "don't touch
            // the existing rows"), so StaffForm.vue sends an explicit empty string
            // ("educations") to clear every row; the ConvertEmptyStringsToNull
            // middleware turns that into null, which StaffService::extractChildData()
            // then treats the same as [] rather than "not sent".
            'educations' => 'sometimes|nullable|array|max:50',
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
            // See the "educations" rule above for why this is nullable.
            'trainings' => 'sometimes|nullable|array|max:50',
            'trainings.*.id' => 'sometimes|nullable|integer',
            'trainings.*.title' => 'required|string|max:255',
            'trainings.*.organizer' => 'nullable|string|max:255',
            'trainings.*.duration' => 'nullable|string|max:100',
            'trainings.*.year' => 'nullable|string|max:20',
        ];
    }
}
