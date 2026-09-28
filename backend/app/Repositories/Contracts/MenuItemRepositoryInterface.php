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
     * True when $candidate is somewhere in $item's own subtree, i.e. moving $item
     * under $candidate would create a cycle. Does not check $candidate === $item.
     */
    public function isDescendantOf(MenuItem $candidate, MenuItem $item): bool;

    /**
     * Persist new parent/sort_order values for a batch of items.
     *
     * @param  list<array{id: int, parent_id: ?int, sort_order: int}>  $rows
     */
    public function reorder(array $rows): void;
}
