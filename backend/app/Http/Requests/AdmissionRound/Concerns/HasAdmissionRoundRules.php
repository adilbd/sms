<?php

namespace App\Http\Requests\AdmissionRound\Concerns;

use Illuminate\Validation\Rule;

/**
 * Field rules shared by the store and update requests. Whether the round has a name and
 * whether the window is in order are checked in AdmissionRoundService against the round
 * with the input applied, so a partial update is still checked.
 */
trait HasAdmissionRoundRules
{
    /**
     * @return array<string, mixed>
     */
    protected function roundRules(string $presence): array
    {
        return [
            'academic_year_id' => [$presence, 'integer', Rule::exists('academic_years', 'id')->whereNull('deleted_at')],
            'name_en' => 'nullable|string|max:255',
            'name_bn' => 'nullable|string|max:255',
            'opens_at' => [$presence, 'date_format:Y-m-d'],
            'closes_at' => [$presence, 'date_format:Y-m-d'],
            'is_published' => 'sometimes|boolean',
            'instructions_bn' => 'nullable|string|max:20000',
            'instructions_en' => 'nullable|string|max:20000',
            'classes' => [$presence, 'array', 'min:1', 'max:12'],
            'classes.*.class_id' => ['required', 'integer', 'distinct', Rule::exists('classes', 'id')->whereNull('deleted_at')],
            'classes.*.seats' => 'nullable|integer|min:1|max:100000',
        ];
    }
}
