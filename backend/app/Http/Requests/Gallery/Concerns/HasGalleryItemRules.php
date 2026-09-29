<?php

namespace App\Http\Requests\Gallery\Concerns;

use App\Http\Requests\Gallery\Rules\ValidYoutubeUrl;
use App\Models\GalleryItem;
use Illuminate\Validation\Rule;

/**
 * Validation rules for the "items" array carried by the gallery store/update payload
 * (see GalleryService::syncItems()). Shared by Store/UpdateGalleryRequest.
 */
trait HasGalleryItemRules
{
    protected function itemRules(): array
    {
        return [
            'items' => 'sometimes|array',
            // Only meaningful on update: an id that isn't one of the gallery's own
            // items is treated as a new item (see GalleryRepository::syncItems()).
            'items.*.id' => 'sometimes|nullable|integer',
            'items.*.type' => ['required', Rule::in(GalleryItem::TYPES)],
            'items.*.media_id' => [
                'nullable', 'integer', Rule::exists('media', 'id'),
                'required_if:items.*.type,'.GalleryItem::TYPE_IMAGE,
            ],
            'items.*.youtube_url' => [
                'nullable', 'string', 'max:2048', new ValidYoutubeUrl,
                'required_if:items.*.type,'.GalleryItem::TYPE_VIDEO,
            ],
            'items.*.caption' => 'nullable|string|max:255',
        ];
    }
}
