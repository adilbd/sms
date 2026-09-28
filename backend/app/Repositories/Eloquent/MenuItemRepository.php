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
        return parent::create($attributes)->load('page');
    }

    public function update(Model $model, array $attributes): Model
    {
        return parent::update($model, $attributes)->load('page');
    }

    public function activeTree(string $location): Collection
    {
        return parent::query()
            ->where('location', $location)
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

    public function subtreeHeight(MenuItem $item): int
    {
        return $this->computeSubtreeHeight($item->id);
    }

    private function computeSubtreeHeight(int $itemId, int $guard = 0): int
    {
        if ($guard > MenuItem::MAX_DEPTH + 5) {
            return 1;
        }

        $childIds = parent::query()->where('parent_id', $itemId)->pluck('id');

        if ($childIds->isEmpty()) {
            return 1;
        }

        return 1 + $childIds->max(fn ($childId) => $this->computeSubtreeHeight($childId, $guard + 1));
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

    public function maxSortOrder(string $location, ?int $parentId): ?int
    {
        $max = parent::query()
            ->where('location', $location)
            ->where('parent_id', $parentId)
            ->max('sort_order');

        return $max === null ? null : (int) $max;
    }

    public function treeState(): Collection
    {
        return parent::query()->get(['id', 'parent_id', 'location']);
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
            ->with('page')
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
