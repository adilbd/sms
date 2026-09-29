<?php

namespace App\Http\Resources;

use App\Models\GalleryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GalleryItem */
class GalleryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'media_id' => $this->media_id,
            'media' => new MediaResource($this->whenLoaded('media')),
            'youtube_url' => $this->youtube_url,
            'youtube_id' => $this->youtube_id,
            'embed_url' => $this->embedUrl(),
            'thumbnail_url' => $this->thumbnailUrl(),
            'caption' => $this->caption,
            'sort_order' => $this->sort_order,
        ];
    }
}
