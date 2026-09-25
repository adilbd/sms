<?php

namespace App\Http\Resources;

use App\Models\Page;
use App\Support\PostBody;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Page */
class PageResource extends JsonResource
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
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => PostBody::plainText($this->body),
            'body' => $this->when($this->withBody, $this->body),
            'meta_title' => $this->meta_title,
            'meta_description' => $this->meta_description,
            'is_published' => (bool) $this->is_published,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'web_url' => $this->url(),
        ];
    }
}
