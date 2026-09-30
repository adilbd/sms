<?php

namespace App\Http\Resources;

use App\Models\Section;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Section */
class SectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_id' => $this->class_id,
            'shift_id' => $this->shift_id,
            'name' => $this->name,
            'code' => $this->code,
            'capacity' => $this->capacity,
            'group' => $this->group,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'class' => new ClassResource($this->whenLoaded('class')),
            'shift' => new ShiftResource($this->whenLoaded('shift')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
