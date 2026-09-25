<?php

namespace App\Http\Resources;

use App\Models\Post;
use App\Support\PostBody;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Post */
class PostResource extends JsonResource
{
    /**
     * Include the full body (detail views) or only summary fields (lists).
     */
    public bool $withBody = false;

    public function withBody(): static
    {
        $this->withBody = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt ?: PostBody::plainText($this->body),
            // The body is stored as sanitized HTML (see App\Support\PostBody), so both
            // keys hold the same value. body_html is kept for existing API clients.
            'body' => $this->when($this->withBody, $this->body),
            'body_html' => $this->when($this->withBody, $this->body),
            'cover_image_url' => $this->coverImageUrl(),
            'event_starts_at' => $this->event_starts_at?->toIso8601String(),
            'event_ends_at' => $this->event_ends_at?->toIso8601String(),
            'location' => $this->location,
            'is_published' => (bool) $this->is_published,
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'web_url' => $this->url(),
        ];
    }
}
