<?php

namespace App\Repositories\Contracts;

use App\Models\Gallery;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface GalleryRepositoryInterface extends RepositoryInterface
{
    public function findPublishedBySlug(string $slug): Gallery;

    public function paginatePublished(int $perPage): LengthAwarePaginator;

    /**
     * Published galleries for the sitemap, newest first.
     */
    public function publishedForSitemap(): Collection;

    /**
     * Replaces a gallery's items with the given list in one pass: existing ids present
     * in $items are updated, ids that aren't sent are deleted, and everything else is
     * created. sort_order is the item's position in $items.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function syncItems(Gallery $gallery, array $items): Gallery;
}
