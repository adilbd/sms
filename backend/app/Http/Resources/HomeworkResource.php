<?php

namespace App\Http\Resources;

use App\Models\Homework;
use App\Services\HomeworkService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Homework
 *
 * `attachment_url` is the authenticated staff endpoint by default. For students and
 * guardians (`/api/my/homework`) use signedCollection(): the URL is then a short-lived
 * signed link. The author and section are narrow shapes (no contact details or other
 * personal fields), since students and guardians read this too.
 */
class HomeworkResource extends JsonResource
{
    private bool $signed = false;

    public function signedAttachment(bool $signed = true): static
    {
        $this->signed = $signed;

        return $this;
    }

    public static function signedCollection(mixed $items): AnonymousResourceCollection
    {
        $collection = static::collection($items);
        $collection->collection->each(fn (HomeworkResource $resource) => $resource->signedAttachment());

        return $collection;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_year_id' => $this->academic_year_id,
            'section_id' => $this->section_id,
            'subject_id' => $this->subject_id,
            'staff_id' => $this->staff_id,
            'title' => $this->title,
            // Sanitized HTML.
            'details' => $this->details,
            'assigned_on' => $this->assigned_on?->toDateString(),
            'due_on' => $this->due_on?->toDateString(),
            'is_overdue' => $this->due_on !== null && $this->due_on->toDateString() < HomeworkService::today(),
            'has_attachment' => $this->attachment_path !== null,
            'attachment_name' => $this->attachment_name,
            'attachment_url' => $this->attachment_path === null
                ? null
                : ($this->signed ? $this->signedAttachmentUrl() : route('homework.attachment', ['homework' => $this->id])),
            'subject' => $this->whenLoaded('subject', fn () => [
                'id' => $this->subject->id,
                'name' => $this->subject->name,
                'name_bn' => $this->subject->name_bn,
                'code' => $this->subject->code,
            ]),
            'staff' => $this->whenLoaded('staff', fn () => $this->staff ? [
                'id' => $this->staff->id,
                'name_en' => $this->staff->name_en,
                'name_bn' => $this->staff->name_bn,
                'designation' => $this->staff->designation,
            ] : null),
            'section' => $this->whenLoaded('section', fn () => [
                'id' => $this->section->id,
                'class_id' => $this->section->class_id,
                'name' => $this->section->name,
                'group' => $this->section->group,
                'class' => $this->section->relationLoaded('class') && $this->section->class ? [
                    'id' => $this->section->class->id,
                    'name' => $this->section->class->name,
                    'name_bn' => $this->section->class->name_bn,
                    'number' => $this->section->class->number,
                ] : null,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
