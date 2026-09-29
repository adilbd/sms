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
            // Capped well above any real gallery so a malicious or buggy client can't
            // force the sync transaction to process an unbounded number of rows.
            'items' => 'sometimes|array|max:200',
            // Only meaningful on update: an id that isn't one of the gallery's own
            // items is treated as a new item (see GalleryRepository::syncItems()).
            'items.*.id' => 'sometimes|nullable|integer',
            'items.*.type' => ['required', Rule::in(GalleryItem::TYPES)],
            'items.*.media_id' => [
                'nullable', 'integer', Rule::exists('media', 'id'),
                'required_if:items.*.type,'.GalleryItem::TYPE_IMAGE,
                // "prohibited" treats null/empty as absent, so a video item that
                // explicitly sends media_id: null still passes (see GalleryForm.vue).
                'prohibited_unless:items.*.type,'.GalleryItem::TYPE_IMAGE,
            ],
            'items.*.youtube_url' => [
                'nullable', 'string', 'max:2048', new ValidYoutubeUrl,
                'required_if:items.*.type,'.GalleryItem::TYPE_VIDEO,
                'prohibited_unless:items.*.type,'.GalleryItem::TYPE_VIDEO,
            ],
            'items.*.caption' => 'nullable|string|max:255',
        ];
    }
}
