<?php

namespace App\Http\Resources;

use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MenuItem */
class MenuItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location' => $this->location,
            'parent_id' => $this->parent_id,
            'label' => $this->label,
            'type' => $this->type,
            'page_id' => $this->page_id,
            'route_name' => $this->route_name,
            'url' => $this->url,
            'href' => $this->href(),
            'sort_order' => $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'open_in_new_tab' => (bool) $this->open_in_new_tab,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'page' => new PageResource($this->whenLoaded('page')),
            'children' => MenuItemResource::collection($this->whenLoaded('children')),
        ];
    }
}
