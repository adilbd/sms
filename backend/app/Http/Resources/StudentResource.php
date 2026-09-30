<?php

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Student
 *
 * Admin API and the signed-in student's/guardian's own-record endpoints. The login
 * password is never exposed; `username` is the student ID the student signs in with.
 */
class StudentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'username' => $this->whenLoaded('user', fn () => $this->user?->username),
            'user_id' => $this->user_id,
            'name_en' => $this->name_en,
            'name_bn' => $this->name_bn,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'gender' => $this->gender,
            'religion' => $this->religion,
            'blood_group' => $this->blood_group,
            'birth_registration_number' => $this->birth_registration_number,
            'nationality' => $this->nationality,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'present_address' => $this->present_address,
            'permanent_address' => $this->permanent_address,
            'district' => $this->district,
            'photo_url' => $this->photoUrl(),
            'admission_date' => $this->admission_date?->toDateString(),
            'status' => $this->status,
            'leaving_date' => $this->leaving_date?->toDateString(),
            'father' => [
                'name_en' => $this->father_name_en,
                'name_bn' => $this->father_name_bn,
                'mobile' => $this->father_mobile,
                'occupation' => $this->father_occupation,
            ],
            'mother' => [
                'name_en' => $this->mother_name_en,
                'name_bn' => $this->mother_name_bn,
                'mobile' => $this->mother_mobile,
                'occupation' => $this->mother_occupation,
            ],
            'guardian' => [
                'relation' => $this->guardian_relation,
                'name' => $this->guardian_name,
                'mobile' => $this->guardian_mobile,
                'user_id' => $this->guardian_user_id,
            ],
            'current_enrolment' => new StudentEnrolmentResource($this->whenLoaded('currentEnrolment')),
            'enrolments' => StudentEnrolmentResource::collection($this->whenLoaded('enrolments')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
