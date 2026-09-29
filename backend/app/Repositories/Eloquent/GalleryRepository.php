<?php

namespace App\Repositories\Eloquent;

use App\Models\Gallery;
use App\Repositories\Contracts\GalleryRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class GalleryRepository extends EloquentRepository implements GalleryRepositoryInterface
{
    protected string $model = Gallery::class;

    public function findPublishedBySlug(string $slug): Gallery
    {
        return Gallery::published()
            ->withCount('items')
            ->with(['coverMedia', 'items.media'])
            ->where('slug', $slug)
            ->firstOrFail();
    }

    public function paginatePublished(int $perPage): LengthAwarePaginator
    {
        return Gallery::published()
            ->withCount('items')
            ->with(['coverMedia', 'items.media'])
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->paginate($perPage);
    }

    public function publishedForSitemap(): Collection
    {
        return Gallery::published()->orderByDesc('published_at')
            ->select(['id', 'slug', 'updated_at'])
            ->get();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function syncItems(Gallery $gallery, array $items): Gallery
    {
        $existingIds = $gallery->items()->pluck('id')->all();
        $keepIds = [];

        foreach ($items as $position => $item) {
            $attributes = [
                'type' => $item['type'],
                'media_id' => $item['media_id'] ?? null,
                'youtube_url' => $item['youtube_url'] ?? null,
                'youtube_id' => $item['youtube_id'] ?? null,
                'caption' => $item['caption'] ?? null,
                'sort_order' => $position,
            ];

            $id = $item['id'] ?? null;

            if ($id && in_array($id, $existingIds, true)) {
                $gallery->items()->whereKey($id)->update($attributes);
                $keepIds[] = $id;
            } else {
                $keepIds[] = $gallery->items()->create($attributes)->id;
            }
        }

        // Anything not present in this batch (including every existing item, when
        // $items is empty) is removed. Deleting gallery items never touches the media
        // rows they point at.
        $gallery->items()->whereNotIn('id', $keepIds)->delete();

        return $gallery->load(['coverMedia', 'items.media'])->loadCount('items');
    }

    protected function query(): Builder
    {
        return parent::query()
            ->withCount('items')
            ->with(['coverMedia', 'items.media'])
            ->orderBy('sort_order')
            ->orderByDesc('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (filled($filters['search'] ?? null)) {
            $query->where('title', 'like', '%'.$filters['search'].'%');
        }

        if (filled($filters['is_published'] ?? null)) {
            $query->where('is_published', filter_var($filters['is_published'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query;
    }
}
