<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Repositories\Contracts\GalleryRepositoryInterface;
use App\Support\YouTube;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin CRUD for galleries, plus the public reads (paginatePublished(),
 * findPublishedBySlug(), publishedForSitemap()) shared by the Blade site and
 * /api/public/galleries. See docs/architecture-guidelines.md; mirrors Page/Post.
 */
class GalleryService
{
    public function __construct(private GalleryRepositoryInterface $galleries) {}

    /**
     * @param  array{search?: string, is_published?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->galleries->paginate($filters, $perPage);
    }

    public function find(Gallery $gallery): Gallery
    {
        return $gallery->load(['coverMedia', 'items.media'])->loadCount('items');
    }

    public function create(array $data): Gallery
    {
        $items = $this->normalizeItems($data['items'] ?? []);
        unset($data['items']);

        return DB::transaction(function () use ($data, $items) {
            $gallery = $this->galleries->create($data);

            return $this->galleries->syncItems($gallery, $items);
        });
    }

    public function update(Gallery $gallery, array $data): Gallery
    {
        $hasItems = array_key_exists('items', $data);
        $items = $hasItems ? $this->normalizeItems($data['items']) : null;
        unset($data['items']);

        return DB::transaction(function () use ($gallery, $data, $items) {
            $gallery = $this->galleries->update($gallery, $data);

            return $items === null
                ? $gallery->load(['coverMedia', 'items.media'])->loadCount('items')
                : $this->galleries->syncItems($gallery, $items);
        });
    }

    public function delete(Gallery $gallery): void
    {
        // gallery_id is cascadeOnDelete on gallery_items, so this removes the
        // gallery's items too, but never the media rows or files those items point at.
        $this->galleries->delete($gallery);
    }

    public function findPublishedBySlug(string $slug): Gallery
    {
        return $this->galleries->findPublishedBySlug($slug);
    }

    public function paginatePublished(int $perPage = 9): LengthAwarePaginator
    {
        return $this->galleries->paginatePublished($perPage);
    }

    public function publishedForSitemap(): Collection
    {
        return $this->galleries->publishedForSitemap();
    }

    /**
     * Computes youtube_id for every video item, using the one place (App\Support\
     * YouTube) that parses a YouTube URL. The store/update FormRequest already
     * validated each url with the same helper (see Gallery\Rules\ValidYoutubeUrl), so
     * this never produces null for input that passed validation.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function normalizeItems(array $items): array
    {
        return array_map(function (array $item) {
            if (($item['type'] ?? null) === GalleryItem::TYPE_VIDEO && ! empty($item['youtube_url'])) {
                $item['youtube_id'] = YouTube::extractId($item['youtube_url']);
            }

            return $item;
        }, $items);
    }
}
