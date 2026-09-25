<?php

namespace App\Repositories\Eloquent;

use App\Models\Page;
use App\Repositories\Contracts\PageRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PageRepository extends EloquentRepository implements PageRepositoryInterface
{
    protected string $model = Page::class;

    public function findPublishedBySlug(string $slug): Page
    {
        return Page::published()->where('slug', $slug)->firstOrFail();
    }

    public function publishedForSitemap(): Collection
    {
        return Page::published()->orderByDesc('published_at')
            ->select(['id', 'slug', 'updated_at'])
            ->get();
    }

    protected function query(): Builder
    {
        return parent::query()->latest()->orderBy('id', 'desc');
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
