<?php

namespace App\Http\Resources;

use App\Models\AdmissionApplication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AdmissionApplication
 *
 * The admin API. Sensitive fields (birth registration number, the three mobile numbers,
 * the guardian's email, both addresses and the admin note) are opt-in, like StudentResource: they are
 * omitted unless the controller calls withSensitive(), which it does for callers with
 * `edit-students`. The uploaded files are never URLs; `files` only says which exist, and
 * the admin downloads them through the authenticated files endpoint. The abuse-check IP
 * hash is never exposed.
 */
class AdmissionApplicationResource extends JsonResource
{
    public bool $sensitive = false;

    public function withSensitive(bool $sensitive = true): static
    {
        $this->sensitive = $sensitive;

        return $this;
    }

    /**
     * A collection whose items all carry the same sensitivity.
     */
    public static function collectionFor(mixed $items, bool $sensitive): AnonymousResourceCollection
    {
        $collection = static::collection($items);
        $collection->collection->each(fn (AdmissionApplicationResource $resource) => $resource->withSensitive($sensitive));

        return $collection;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'application_no' => $this->application_no,
            'round_id' => $this->round_id,
            'round' => $this->whenLoaded('round', fn () => [
                'id' => $this->round->id,
                'name_en' => $this->round->name_en,
                'name_bn' => $this->round->name_bn,
                'academic_year_id' => $this->round->academic_year_id,
            ]),
            'class_id' => $this->class_id,
            'class' => new ClassResource($this->whenLoaded('class')),
            'group' => $this->group,
            'shift_id' => $this->shift_id,
            'shift' => new ShiftResource($this->whenLoaded('shift')),
            'name_en' => $this->name_en,
            'name_bn' => $this->name_bn,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender,
            'religion' => $this->religion,
            'birth_registration_number' => $this->when($this->sensitive, $this->birth_registration_number),
            'blood_group' => $this->blood_group,
            'nationality' => $this->nationality,
            'previous_school' => $this->previous_school,
            'previous_class' => $this->previous_class,
            'father' => [
                'name_en' => $this->father_name_en,
                'name_bn' => $this->father_name_bn,
                'mobile' => $this->when($this->sensitive, $this->father_mobile),
            ],
            'mother' => [
                'name_en' => $this->mother_name_en,
                'name_bn' => $this->mother_name_bn,
                'mobile' => $this->when($this->sensitive, $this->mother_mobile),
            ],
            'guardian' => [
                'relation' => $this->guardian_relation,
                'name' => $this->guardian_name,
                'mobile' => $this->when($this->sensitive, $this->guardian_mobile),
                'email' => $this->when($this->sensitive, $this->guardian_email),
            ],
            'present_address' => $this->when($this->sensitive, $this->present_address),
            'permanent_address' => $this->when($this->sensitive, $this->permanent_address),
            'district' => $this->district,
            'files' => [
                'photo' => $this->photo_path !== null,
                'birth_certificate' => $this->birth_certificate_path !== null,
                'previous_school_doc' => $this->previous_school_doc_path !== null,
            ],
            'status' => $this->status,
            'test_at' => $this->test_at?->toIso8601String(),
            'test_venue' => $this->test_venue,
            'test_score' => $this->test_score,
            'admin_note' => $this->when($this->sensitive, $this->admin_note),
            'decided_by' => $this->decided_by,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'student_id' => $this->student_id,
            'student' => $this->whenLoaded('student', fn () => $this->student ? [
                'id' => $this->student->id,
                'student_id' => $this->student->student_id,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
