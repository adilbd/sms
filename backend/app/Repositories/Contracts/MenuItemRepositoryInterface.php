<?php

namespace App\Repositories\Contracts;

use App\Models\MenuItem;
use Illuminate\Database\Eloquent\Collection;

interface MenuItemRepositoryInterface extends RepositoryInterface
{
    /**
     * Every active item for a location, flat (not nested), ordered, with its page
     * eager loaded so MenuService can build and filter the tree in PHP.
     */
    public function activeTree(string $location): Collection;

    /**
     * 1 for a top-level item, 2 for a child of a top-level item, and so on.
     */
    public function depthOf(MenuItem $item): int;

    /**
     * The height of the subtree rooted at $item: 1 for a leaf, 2 if it has children
     * but no grandchildren, and so on. Used so moving an item that itself has
     * children accounts for how deep its own descendants would then sit.
     */
    public function subtreeHeight(MenuItem $item): int;

    /**
     * True when $candidate is somewhere in $item's own subtree, i.e. moving $item
     * under $candidate would create a cycle. Does not check $candidate === $item.
     */
    public function isDescendantOf(MenuItem $candidate, MenuItem $item): bool;

    /**
     * The highest sort_order among siblings sharing this location and parent_id, or
     * null when there are none yet, so a new item can default to max + 1.
     */
    public function maxSortOrder(string $location, ?int $parentId): ?int;

    /**
     * Every menu item's id, parent_id and location (regardless of location or active
     * state), used to validate a reorder batch against the tree it would produce
     * without a query per row.
     */
    public function treeState(): Collection;

    /**
     * Persist new parent/sort_order values for a batch of items.
     *
     * @param  list<array{id: int, parent_id: ?int, sort_order: int}>  $rows
     */
    public function reorder(array $rows): void;
}
