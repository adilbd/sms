<?php

namespace App\Services;

use App\Models\MenuItem;
use App\Repositories\Contracts\MenuItemRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin CRUD for menu items, plus headerTree(), the read the public site uses to
 * render the header nav. See docs/architecture-guidelines.md; MenuItem follows the
 * Subjects pattern.
 */
class MenuService
{
    public function __construct(private MenuItemRepositoryInterface $menuItems) {}

    /**
     * @param  array{location?: string, is_active?: mixed, parent_id?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->menuItems->paginate($filters, $perPage);
    }

    public function find(MenuItem $menuItem): MenuItem
    {
        return $menuItem->load('page');
    }

    public function create(array $data): MenuItem
    {
        $this->applyBusinessRules($data, null);

        if (! array_key_exists('sort_order', $data)) {
            $location = $data['location'] ?? MenuItem::LOCATION_HEADER;
            $max = $this->menuItems->maxSortOrder($location, $data['parent_id'] ?? null);
            $data['sort_order'] = $max === null ? 0 : $max + 1;
        }

        // The Eloquent repository eager loads 'page' after the write, so this stays
        // free of extra queries here and testable with a fully mocked repository.
        return $this->menuItems->create($data);
    }

    public function update(MenuItem $menuItem, array $data): MenuItem
    {
        if (array_key_exists('location', $data)
            && $data['location'] !== $menuItem->location
            && $this->menuItems->subtreeHeight($menuItem) > 1
        ) {
            throw ValidationException::withMessages([
                'location' => ['A menu item with submenu items cannot change location. Move or delete its submenu items first.'],
            ]);
        }

        $this->applyBusinessRules($data, $menuItem);

        return $this->menuItems->update($menuItem, $data);
    }

    public function delete(MenuItem $menuItem): void
    {
        // parent_id is cascadeOnDelete, so deleting a parent removes its children too.
        $this->menuItems->delete($menuItem);
    }

    /**
     * @param  list<array{id: int, parent_id: ?int, sort_order: int}>  $rows
     */
    public function reorder(array $rows): void
    {
        DB::transaction(function () use ($rows) {
            $this->assertValidReorderBatch($rows);

            $this->menuItems->reorder($rows);
        });

        Cache::forget('menu.header');
    }

    /**
     * The nested, filtered tree the public header renders. Cached until the earliest
     * future published_at among linked, published pages, capped at one hour, so a
     * page that's scheduled to publish appears without anyone saving anything. Save
     * and delete hooks on MenuItem and Page still clear the 'menu.header' key eagerly.
     *
     * @return list<array{id: int, label: string, type: string, href: ?string, open_in_new_tab: bool, children: array}>
     */
    public function headerTree(): array
    {
        $cached = Cache::get('menu.header');

        if ($cached !== null) {
            return $cached;
        }

        $items = $this->menuItems->activeTree(MenuItem::LOCATION_HEADER);
        $tree = $this->buildVisibleTree($items, null);

        Cache::put('menu.header', $tree, $this->headerCacheExpiry($items));

        return $tree;
    }

    /**
     * One hour from now, or the earliest published_at among the tree's linked,
     * published-but-not-yet-live pages, whichever is sooner.
     *
     * @param  Collection<int, MenuItem>  $items
     */
    private function headerCacheExpiry(Collection $items): Carbon
    {
        $capped = now()->addHour();

        $earliestFuturePublish = $items
            ->filter(fn (MenuItem $item) => $item->type === MenuItem::TYPE_PAGE
                && $item->page?->is_published
                && $item->page->published_at?->isFuture())
            ->min(fn (MenuItem $item) => $item->page->published_at);

        return $earliestFuturePublish && $earliestFuturePublish->lt($capped) ? $earliestFuturePublish : $capped;
    }

    /**
     * Validate a whole reorder batch against the tree it would produce: build an
     * in-memory parent_id map for every menu item, apply the batch's new parent_ids
     * to it, then check every moved row for cycles (including a row naming itself as
     * its own parent), the maximum nesting depth (accounting for the moved item's own
     * subtree), and that its new parent stays in the same menu location. Checking the
     * resulting map, rather than each row against the pre-batch database, is what
     * catches a batch that introduces a cycle between two items moved at once
     * (for example A becomes a child of B while B becomes a child of A).
     *
     * @param  list<array{id: int, parent_id: ?int, sort_order: int}>  $rows
     */
    private function assertValidReorderBatch(array $rows): void
    {
        $items = $this->menuItems->treeState()->keyBy('id');

        $parentOf = $items->map(fn (MenuItem $item) => $item->parent_id)->all();
        $locationOf = $items->map(fn (MenuItem $item) => $item->location)->all();

        foreach ($rows as $row) {
            $parentOf[$row['id']] = $row['parent_id'] ?? null;
        }

        $childrenOf = [];
        foreach ($parentOf as $id => $parentId) {
            if ($parentId !== null) {
                $childrenOf[$parentId][] = $id;
            }
        }

        $guardLimit = count($parentOf) + 1;

        foreach ($rows as $row) {
            $id = $row['id'];
            $parentId = $row['parent_id'] ?? null;

            if ($parentId === null) {
                continue;
            }

            if (($locationOf[$parentId] ?? null) !== ($locationOf[$id] ?? null)) {
                throw ValidationException::withMessages([
                    'items' => ["Menu item {$id}'s parent must be in the same menu location."],
                ]);
            }

            $current = $parentId;
            $guard = 0;

            while ($current !== null) {
                if ($current === $id) {
                    throw ValidationException::withMessages([
                        'items' => ["Menu item {$id} cannot be moved under itself or one of its own submenu items."],
                    ]);
                }

                if (++$guard > $guardLimit) {
                    throw ValidationException::withMessages([
                        'items' => ['The reorder would create a cycle.'],
                    ]);
                }

                $current = $parentOf[$current] ?? null;
            }

            $depthOfParent = $this->depthInMap($parentId, $parentOf, $guardLimit);
            $height = $this->subtreeHeightInMap($id, $childrenOf, $guardLimit);

            if ($depthOfParent + $height > MenuItem::MAX_DEPTH) {
                throw ValidationException::withMessages([
                    'items' => ["Menu item {$id} cannot be nested more than 3 levels deep."],
                ]);
            }
        }
    }

    /**
     * @param  array<int, ?int>  $parentOf
     */
    private function depthInMap(int $id, array $parentOf, int $guardLimit, int $guard = 0): int
    {
        $parentId = $parentOf[$id] ?? null;

        if ($parentId === null || $guard >= $guardLimit) {
            return 1;
        }

        return 1 + $this->depthInMap($parentId, $parentOf, $guardLimit, $guard + 1);
    }

    /**
     * @param  array<int, list<int>>  $childrenOf
     */
    private function subtreeHeightInMap(int $id, array $childrenOf, int $guardLimit, int $guard = 0): int
    {
        $children = $childrenOf[$id] ?? [];

        if ($children === [] || $guard >= $guardLimit) {
            return 1;
        }

        return 1 + max(array_map(
            fn ($childId) => $this->subtreeHeightInMap($childId, $childrenOf, $guardLimit, $guard + 1),
            $children
        ));
    }

    /**
     * @param  Collection<int, MenuItem>  $items
     */
    private function buildVisibleTree(Collection $items, ?int $parentId): array
    {
        return $items->where('parent_id', $parentId)
            ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
            ->map(function (MenuItem $item) use ($items) {
                $children = $this->buildVisibleTree($items, $item->id);

                if ($item->type === MenuItem::TYPE_HEADING && $children === []) {
                    return null;
                }

                if ($item->type === MenuItem::TYPE_PAGE && ! $this->pageIsVisible($item)) {
                    return null;
                }

                return [
                    'id' => $item->id,
                    'label' => $item->label,
                    'type' => $item->type,
                    'href' => $item->href(),
                    'open_in_new_tab' => (bool) $item->open_in_new_tab,
                    'children' => $children,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function pageIsVisible(MenuItem $item): bool
    {
        $page = $item->page;

        return $page
            && $page->is_published
            && $page->published_at
            && $page->published_at->lessThanOrEqualTo(now());
    }

    /**
     * Business rules that depend on saved state, checked against the model with the
     * input applied, as (clone $item)->fill($data): the maximum nesting depth, no
     * cycles, the parent staying in the same location, and the target required by
     * the item's type.
     */
    private function applyBusinessRules(array $data, ?MenuItem $existing): void
    {
        $prototype = $existing ? (clone $existing)->fill($data) : new MenuItem($data);

        if (array_key_exists('parent_id', $data) && $data['parent_id'] !== null) {
            // A brand-new item that doesn't set 'location' still gets the DB default
            // ('header') once saved, so fall back to it for the same-location check.
            $this->assertValidParent($existing, $data['parent_id'], $prototype->location ?: MenuItem::LOCATION_HEADER);
        }

        match ($prototype->type) {
            MenuItem::TYPE_PAGE => $this->requireField($prototype->page_id, 'page_id', 'A page must be selected for this menu item.'),
            MenuItem::TYPE_ROUTE => $this->requireField($prototype->route_name, 'route_name', 'A route must be selected for this menu item.'),
            MenuItem::TYPE_URL => $this->requireField($prototype->url, 'url', 'A URL is required for this menu item.'),
            default => null,
        };
    }

    private function assertValidParent(?MenuItem $item, ?int $parentId, ?string $location = null): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = $this->menuItems->find($parentId);

        if (! $parent) {
            throw ValidationException::withMessages([
                'parent_id' => ['The selected parent does not exist.'],
            ]);
        }

        if ($item) {
            if ($parent->id === $item->id || $this->menuItems->isDescendantOf($parent, $item)) {
                throw ValidationException::withMessages([
                    'parent_id' => ['A menu item cannot be moved under itself or one of its own submenu items.'],
                ]);
            }

            $location ??= $item->location;
        }

        if ($location && $parent->location !== $location) {
            throw ValidationException::withMessages([
                'parent_id' => ['The parent must be in the same menu location.'],
            ]);
        }

        // A brand-new item is just itself (height 1). An existing item being moved
        // brings its own subtree along, so a parent with children can't be dropped
        // somewhere that would push its descendants past the maximum depth.
        $height = $item ? $this->menuItems->subtreeHeight($item) : 1;

        if ($this->menuItems->depthOf($parent) + $height > MenuItem::MAX_DEPTH) {
            throw ValidationException::withMessages([
                'parent_id' => ['A menu item cannot be nested more than 3 levels deep.'],
            ]);
        }
    }

    private function requireField(mixed $value, string $field, string $message): void
    {
        if (blank($value)) {
            throw ValidationException::withMessages([$field => [$message]]);
        }
    }
}
