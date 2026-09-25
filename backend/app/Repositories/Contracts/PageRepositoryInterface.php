<?php

namespace App\Repositories\Contracts;

use App\Models\Page;
use Illuminate\Database\Eloquent\Collection;

interface PageRepositoryInterface extends RepositoryInterface
{
    public function findPublishedBySlug(string $slug): Page;

    /**
     * Published pages for the sitemap, newest first.
     */
    public function publishedForSitemap(): Collection;
}
