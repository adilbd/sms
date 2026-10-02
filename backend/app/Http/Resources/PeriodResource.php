<?php

namespace App\Http\Resources;

use App\Models\Period;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Period */
class PeriodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shift_id' => $this->shift_id,
            'number' => $this->number,
            'name_en' => $this->name_en,
            'name_bn' => $this->name_bn,
            // Asia/Dhaka wall-clock "H:i" (stored as "H:i:s").
            'start_time' => substr((string) $this->start_time, 0, 5),
            'end_time' => substr((string) $this->end_time, 0, 5),
            'is_break' => $this->is_break,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
