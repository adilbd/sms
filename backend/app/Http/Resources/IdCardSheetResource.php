<?php

namespace App\Http\Resources;

use App\Models\StudentEnrolment;
use App\Support\AcademicGroup;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The ID card print data from IdCardService (an array, not a model): the school block,
 * the academic year and one card per active enrolment. Not a register entry.
 *
 * @property array{academic_year: \App\Models\AcademicYear, school: array<string, mixed>, cards: \Illuminate\Support\Collection<int, StudentEnrolment>} $resource
 */
class IdCardSheetResource extends JsonResource
{
    public bool $sensitive = false;

    /**
     * Includes the guardian's mobile number; the controller opts in for callers with
     * `edit-students`, like StudentResource::withSensitive().
     */
    public function withSensitive(bool $sensitive = true): static
    {
        $this->sensitive = $sensitive;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $year = $this->resource['academic_year'];

        return [
            'academic_year' => ['id' => $year->id, 'name' => $year->name, 'year' => $year->year],
            'school' => $this->resource['school'],
            'cards' => $this->resource['cards']->map(fn (StudentEnrolment $e) => [
                'enrolment_id' => $e->id,
                'student_id' => $e->student->student_id,
                'name_en' => $e->student->name_en,
                'name_bn' => $e->student->name_bn,
                'class_name' => $e->class?->name,
                'class_name_bn' => $e->class?->name_bn,
                'section' => $e->section?->name,
                'shift_name_en' => $e->section?->shift?->name_en,
                'shift_name_bn' => $e->section?->shift?->name_bn,
                'roll_number' => $e->roll_number,
                'group' => $e->group,
                'group_en' => $e->group ? (AcademicGroup::LABELS_EN[$e->group] ?? $e->group) : null,
                'group_bn' => $e->group ? (AcademicGroup::LABELS_BN[$e->group] ?? $e->group) : null,
                'blood_group' => $e->student->blood_group,
                'guardian_mobile' => $this->when($this->sensitive, $e->student->guardian_mobile),
                'photo_url' => $e->student->photoUrl(),
                'valid_until' => $e->academicYear?->end_date?->toDateString() ?? $year->end_date?->toDateString(),
            ])->values()->all(),
        ];
    }
}
