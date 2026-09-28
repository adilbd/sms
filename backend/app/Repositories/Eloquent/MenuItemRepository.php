<?php

namespace App\Repositories\Eloquent;

use App\Models\MenuItem;
use App\Repositories\Contracts\MenuItemRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class MenuItemRepository extends EloquentRepository implements MenuItemRepositoryInterface
{
    protected string $model = MenuItem::class;

    public function create(array $attributes): Model
    {
        return parent::create($attributes)->load('page:id,slug,title,is_published');
    }

    public function update(Model $model, array $attributes): Model
    {
        return parent::update($model, $attributes)->load('page:id,slug,title,is_published');
    }

    public function activeTree(string $location): Collection
    {
        return MenuItem::where('location', $location)
            ->where('is_active', true)
            ->with('page:id,slug,is_published,published_at')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function depthOf(MenuItem $item): int
    {
        $depth = 1;
        $current = $item;
        $guard = 0;

        while ($current->parent_id && $guard++ < MenuItem::MAX_DEPTH + 5) {
            $current = $current->parent()->first();

            if (! $current) {
                break;
            }

            $depth++;
        }

        return $depth;
    }

    public function isDescendantOf(MenuItem $candidate, MenuItem $item): bool
    {
        $current = $candidate;
        $guard = 0;

        while ($current->parent_id && $guard++ < MenuItem::MAX_DEPTH + 5) {
            if ($current->parent_id === $item->id) {
                return true;
            }

            $current = $current->parent()->first();

            if (! $current) {
                break;
            }
        }

        return false;
    }

    public function reorder(array $rows): void
    {
        foreach ($rows as $row) {
            $item = $this->findOrFail($row['id']);
            $item->update([
                'parent_id' => $row['parent_id'] ?? null,
                'sort_order' => $row['sort_order'],
            ]);
        }
    }

    protected function query(): Builder
    {
        return parent::query()
            ->with('page:id,slug,title,is_published')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (filled($filters['location'] ?? null)) {
            $query->where('location', $filters['location']);
        }

        if (filled($filters['is_active'] ?? null)) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (filled($filters['parent_id'] ?? null)) {
            $query->where('parent_id', $filters['parent_id']);
        }

        return $query;
    }
}
