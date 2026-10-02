<?php

namespace App\Http\Requests\Routine;

use App\Models\RoutineSlot;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'slots.*.period_id' => ['required', 'integer'],
            'slots.*.subject_id' => ['required', 'integer'],
            'slots.*.staff_id' => ['sometimes', 'nullable', 'integer'],
            'slots.*.room' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }

    /**
     * The period, subject and staff ids must exist. Checked with one query per table for the
     * whole grid (a per-cell `exists` rule would run up to 200 queries each), with the
     * message `exists` would give.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $slots = $this->input('slots');

            if (! is_array($slots)) {
                return;
            }

            foreach (['period_id' => ['periods', false], 'subject_id' => ['subjects', true], 'staff_id' => ['staff', true]] as $field => [$table, $softDeletes]) {
                $ids = [];

                foreach ($slots as $i => $slot) {
                    $value = is_array($slot) ? ($slot[$field] ?? null) : null;

                    if (is_scalar($value) && filter_var($value, FILTER_VALIDATE_INT) !== false && ! $validator->errors()->has("slots.{$i}.{$field}")) {
                        $ids[$i] = (int) $value;
                    }
                }

                if ($ids === []) {
                    continue;
                }

                $found = DB::table($table)
                    ->whereIn('id', array_values(array_unique($ids)))
                    ->when($softDeletes, fn ($q) => $q->whereNull('deleted_at'))
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                foreach ($ids as $i => $id) {
                    if (! in_array($id, $found, true)) {
                        $validator->errors()->add("slots.{$i}.{$field}", __('validation.exists', ['attribute' => str_replace('_', ' ', "slots.{$i}.{$field}")]));
                    }
                }
            }
        }];
    }
}
