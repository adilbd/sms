<?php

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Student
 *
 * Admin API and the signed-in student's/guardian's own-record endpoints. The login
 * password is never exposed; `username` is the student ID the student signs in with.
 *
 * Sensitive fields (birth registration number, both addresses, the father's, mother's and
 * guardian's mobile numbers, and the login user ids) are opt-in, like
 * StaffResource::public() and PostResource::withBody(): they are omitted unless the
 * controller calls withSensitive(), which it does for callers with `edit-students` and
 * for a student or guardian reading their own record. A teacher with only
 * `view-students` therefore never receives them.
 */
class StudentResource extends JsonResource
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
        $collection->collection->each(fn (StudentResource $resource) => $resource->withSensitive($sensitive));

        return $collection;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'username' => $this->whenLoaded('user', fn () => $this->user?->username),
            'user_id' => $this->when($this->sensitive, $this->user_id),
            'name_en' => $this->name_en,
            'name_bn' => $this->name_bn,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender,
            'religion' => $this->religion,
            'blood_group' => $this->blood_group,
            'birth_registration_number' => $this->when($this->sensitive, $this->birth_registration_number),
            'nationality' => $this->nationality,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'present_address' => $this->when($this->sensitive, $this->present_address),
            'permanent_address' => $this->when($this->sensitive, $this->permanent_address),
            'district' => $this->district,
            'photo_url' => $this->photoUrl(),
            'admission_date' => $this->admission_date?->toDateString(),
            'status' => $this->status,
            'leaving_date' => $this->leaving_date?->toDateString(),
            'father' => [
                'name_en' => $this->father_name_en,
                'name_bn' => $this->father_name_bn,
                'mobile' => $this->when($this->sensitive, $this->father_mobile),
                'occupation' => $this->father_occupation,
            ],
            'mother' => [
                'name_en' => $this->mother_name_en,
                'name_bn' => $this->mother_name_bn,
                'mobile' => $this->when($this->sensitive, $this->mother_mobile),
                'occupation' => $this->mother_occupation,
            ],
            'guardian' => [
                'relation' => $this->guardian_relation,
                'name' => $this->guardian_name,
                'mobile' => $this->when($this->sensitive, $this->guardian_mobile),
                'user_id' => $this->when($this->sensitive, $this->guardian_user_id),
            ],
            'current_enrolment' => new StudentEnrolmentResource($this->whenLoaded('currentEnrolment')),
            'enrolments' => StudentEnrolmentResource::collection($this->whenLoaded('enrolments')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
