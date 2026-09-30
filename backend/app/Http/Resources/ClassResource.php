<?php

namespace App\Http\Resources;

use App\Models\Classes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Classes */
class ClassResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'name' => $this->name,
            'name_bn' => $this->name_bn,
            'code' => $this->code,
            'level' => $this->level(),
            'has_groups' => $this->hasGroups(),
            'description' => $this->description,
            'display_order' => $this->display_order,
            'is_active' => $this->is_active,
            'sections' => SectionResource::collection($this->whenLoaded('sections')),
            'sections_count' => $this->whenCounted('sections'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
