<?php

namespace App\Http\Resources;

use App\Models\Gallery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Gallery */
class GalleryResource extends JsonResource
{
    /**
     * Include the full items array (single-gallery reads) or omit it entirely (lists),
     * mirroring PostResource::withBody(). Lists still get cover_url and item_count
     * without shipping every item.
     */
    public bool $withItems = false;

    public function withItems(): static
    {
        $this->withItems = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'cover_media_id' => $this->cover_media_id,
            'cover_url' => $this->coverImageUrl(),
            'item_count' => (int) $this->items_count,
            'items' => $this->when($this->withItems, fn () => GalleryItemResource::collection($this->items)),
            'is_published' => (bool) $this->is_published,
            'published_at' => $this->published_at?->toIso8601String(),
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'web_url' => $this->url(),
        ];
    }
}
