<?php

namespace App\Repositories\Eloquent;

use App\Models\GalleryItem;
use App\Models\Media;
use App\Repositories\Contracts\MediaRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

class MediaRepository extends EloquentRepository implements MediaRepositoryInterface
{
    protected string $model = Media::class;

    public function isUsedInGalleryItems(Media $media): bool
    {
        return GalleryItem::where('media_id', $media->id)->exists();
    }

    protected function query(): Builder
    {
        return parent::query()->orderByDesc('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];

            // Grouped so the OR can't escape other filters.
            $query->where(fn (Builder $q) => $q
                ->where('original_name', 'like', "%{$search}%")
                ->orWhere('alt', 'like', "%{$search}%"));
        }

        return $query;
    }
}
