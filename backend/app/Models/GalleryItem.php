<?php

namespace App\Models;

use App\Support\YouTube;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One photo or YouTube video inside a Gallery. Managed entirely through the gallery's
 * store/update payload (see GalleryService::syncItems()); there is no standalone
 * gallery-item endpoint.
 */
class GalleryItem extends Model
{
    /** @use HasFactory<\Database\Factories\GalleryItemFactory> */
    use HasFactory;

    public const TYPE_IMAGE = 'image';

    public const TYPE_VIDEO = 'video';

    public const TYPES = [self::TYPE_IMAGE, self::TYPE_VIDEO];

    protected $fillable = [
        'gallery_id',
        'type',
        'media_id',
        'youtube_url',
        'youtube_id',
        'caption',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * The image itself, or the video's YouTube thumbnail. Never lazy-loads: returns
     * null for an image item whose `media` relation wasn't eager loaded, rather than
     * issuing a query per item.
     */
    public function thumbnailUrl(): ?string
    {
        return match ($this->type) {
            self::TYPE_IMAGE => $this->relationLoaded('media') ? $this->media?->url() : null,
            self::TYPE_VIDEO => $this->youtube_id ? YouTube::thumbnailUrl($this->youtube_id) : null,
            default => null,
        };
    }

    public function embedUrl(): ?string
    {
        return $this->type === self::TYPE_VIDEO && $this->youtube_id
            ? YouTube::embedUrl($this->youtube_id)
            : null;
    }
}
