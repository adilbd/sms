<?php

namespace App\Http\Resources;

use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

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
            'excerpt' => $this->excerpt ?: Str::limit(trim(strip_tags($this->body)), 160),
            'body' => $this->when($this->withBody, $this->body),
            'body_html' => $this->when($this->withBody, fn () => (string) Str::markdown($this->body, ['html_input' => 'strip', 'allow_unsafe_links' => false])),
            'cover_image_url' => $this->coverImageUrl(),
            'event_starts_at' => $this->event_starts_at?->toIso8601String(),
            'event_ends_at' => $this->event_ends_at?->toIso8601String(),
            'location' => $this->location,
            'published_at' => $this->published_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'web_url' => $this->url(),
        ];
    }
}
