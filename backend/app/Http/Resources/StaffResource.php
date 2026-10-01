<?php

namespace App\Http\Resources;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Staff
 *
 * Used for both the admin API (full fields) and /api/public/staff (public() flag),
 * mirroring GalleryResource::withItems(). The public profile never carries nid,
 * date_of_birth, addresses or mpo_index, and mobile/email only appear when
 * show_contact is set (see CLAUDE.md's Staff module note).
 */
class StaffResource extends JsonResource
{
    public bool $public = false;

    public function public(): static
    {
        $this->public = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $showContact = (bool) $this->show_contact;

        $base = [
            'id' => $this->id,
            'name_en' => $this->name_en,
            'name_bn' => $this->name_bn,
            'category' => $this->category,
            'position' => $this->position,
            'designation' => $this->designation,
            'subject' => $this->subject,
            'joining_date' => $this->joining_date?->toDateString(),
            'leaving_date' => $this->leaving_date?->toDateString(),
            'status' => $this->status,
            'is_former' => $this->isFormer(),
            'photo_url' => $this->photoUrl(),
            'bio' => $this->bio,
            'show_contact' => $showContact,
            'mobile' => ($showContact || ! $this->public) ? $this->mobile : null,
            'email' => ($showContact || ! $this->public) ? $this->email : null,
            'shifts' => ShiftResource::collection($this->whenLoaded('shifts')),
            'educations' => StaffEducationResource::collection($this->whenLoaded('educations')),
            'trainings' => StaffTrainingResource::collection($this->whenLoaded('trainings')),
            'web_url' => $this->url(),
        ];

        if ($this->public) {
            return $base;
        }

        // Admin-only fields: never sent to /api/public/staff.
        return array_merge($base, [
            'user_id' => $this->user_id,
            'employee_id' => $this->employee_id,
            'mpo_index' => $this->mpo_index,
            'gender' => $this->gender,
            'religion' => $this->religion,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'blood_group' => $this->blood_group,
            'nationality' => $this->nationality,
            'nid' => $this->nid,
            'present_address' => $this->present_address,
            'permanent_address' => $this->permanent_address,
            'district' => $this->district,
            'sort_order' => $this->sort_order,
            'is_published' => (bool) $this->is_published,
            // Only when the controller's service loaded `user.roles` (never on the public
            // or other narrow shapes). `enabled` means the member has a login account;
            // `is_active` says whether that account can currently sign in.
            'login' => $this->when($this->resource->relationLoaded('user'), fn () => [
                'enabled' => $this->user !== null,
                'username' => $this->user?->username,
                'email' => $this->user?->email,
                'role' => $this->user?->roles->first()?->name,
                'is_active' => (bool) $this->user?->is_active,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ]);
    }
}
