<?php

namespace App\Http\Resources;

use App\Models\RoutineSlot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin RoutineSlot
 *
 * One cell of a routine. The teacher is a narrow shape (never nid, date of birth,
 * addresses or contact details), since students and guardians read routines too.
 */
class RoutineSlotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_year_id' => $this->academic_year_id,
            'section_id' => $this->section_id,
            'day' => $this->day,
            'period_id' => $this->period_id,
            'subject_id' => $this->subject_id,
            'staff_id' => $this->staff_id,
            'room' => $this->room,
            'period' => new PeriodResource($this->whenLoaded('period')),
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
            'section' => new SectionResource($this->whenLoaded('section')),
        ];
    }
}
