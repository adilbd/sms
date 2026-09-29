<?php

namespace App\Models;

use App\Support\YouTube;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * A named collection of photos and YouTube videos shown on the public /gallery pages.
 * Slug generation, the published() scope and sitemap-cache clearing mirror Page.
 */
class Gallery extends Model
{
    /** @use HasFactory<\Database\Factories\GalleryFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'slug',
        'description',
        'cover_media_id',
        'is_published',
        'published_at',
        'sort_order',
    ];

    protected $casts = [
        'is_published' => 'boolean',
        'published_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (Gallery $gallery) {
            if (empty($gallery->slug)) {
                $gallery->slug = static::uniqueSlug($gallery->title, $gallery->id);
            }

            if ($gallery->is_published && empty($gallery->published_at)) {
                $gallery->published_at = now();
            }
        });

        static::saved(fn () => Cache::forget('sitemap.xml'));
        static::deleted(fn () => Cache::forget('sitemap.xml'));
    }

    public static function uniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title) ?: Str::random(8);
        $slug = $base;
        $i = 2;

        while (static::where('slug', $slug)->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    public function items(): HasMany
    {
        return $this->hasMany(GalleryItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function coverMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /**
     * The gallery's first image item (lowest sort_order, then lowest id), for computing
     * the cover on lists without eager-loading every item. See GalleryRepository::query()
     * and ::paginatePublished(), which load this with 'media' instead of 'items.media'.
     */
    public function firstImageItem(): HasOne
    {
        return $this->hasOne(GalleryItem::class)->ofMany(
            ['sort_order' => 'min', 'id' => 'min'],
            fn (Builder $query) => $query->where('type', GalleryItem::TYPE_IMAGE)
        );
    }

    /**
     * The gallery's first video item, same purpose as firstImageItem() above.
     */
    public function firstVideoItem(): HasOne
    {
        return $this->hasOne(GalleryItem::class)->ofMany(
            ['sort_order' => 'min', 'id' => 'min'],
            fn (Builder $query) => $query->where('type', GalleryItem::TYPE_VIDEO)
        );
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    public function seoTitle(): string
    {
        return $this->title;
    }

    public function seoDescription(): string
    {
        return $this->description ?: "Photos and videos from {$this->title}.";
    }

    public function url(): string
    {
        return route('gallery.show', $this->slug);
    }

    /**
     * The explicit cover if set, otherwise the first image item, otherwise the first
     * video's YouTube thumbnail, otherwise null (the view/resource renders a
     * placeholder). Never lazy-loads: works from whichever of 'coverMedia',
     * 'firstImageItem.media'/'firstVideoItem' (lists) or 'items.media' (single-gallery
     * reads) the caller already eager-loaded.
     */
    public function coverImageUrl(): ?string
    {
        if ($this->relationLoaded('coverMedia') && $this->coverMedia) {
            return $this->coverMedia->url();
        }

        if ($this->relationLoaded('firstImageItem')) {
            if ($this->firstImageItem?->media) {
                return $this->firstImageItem->media->url();
            }
        } elseif ($this->relationLoaded('items')) {
            $firstImage = $this->items->firstWhere('type', GalleryItem::TYPE_IMAGE);
            if ($firstImage?->media) {
                return $firstImage->media->url();
            }
        }

        if ($this->relationLoaded('firstVideoItem')) {
            if ($this->firstVideoItem?->youtube_id) {
                return YouTube::thumbnailUrl($this->firstVideoItem->youtube_id);
            }
        } elseif ($this->relationLoaded('items')) {
            $firstVideo = $this->items->firstWhere('type', GalleryItem::TYPE_VIDEO);
            if ($firstVideo?->youtube_id) {
                return YouTube::thumbnailUrl($firstVideo->youtube_id);
            }
        }

        return null;
    }
}
