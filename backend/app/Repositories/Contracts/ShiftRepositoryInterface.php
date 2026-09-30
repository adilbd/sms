<?php

namespace App\Repositories\Contracts;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Collection;

interface ShiftRepositoryInterface extends RepositoryInterface
{
    public function hasStaff(Shift $shift): bool;

    /**
     * Whether any section uses this shift. sections.shift_id is `restrictOnDelete`, so
     * an unchecked delete would still be blocked, but as a raw database error (500);
     * this lets ShiftService::delete() report it as a clean 409 instead.
     */
    public function isUsedBySections(Shift $shift): bool;

    /**
     * Active shifts, ordered for display. Used to decide whether the public shift
     * filter pills should be shown at all (only when there is more than one).
     */
    public function activeOrdered(): Collection;

    public function findBySlug(string $slug): ?Shift;

    /**
     * Locks the given shift rows for the remainder of the current transaction, so a
     * concurrent request that also touches one of these shifts can't race past a
     * check based on their current state (see StaffService::ensureUniqueHeadPerShift()).
     * A no-op outside a transaction and on databases (SQLite) that don't support
     * row-level locks.
     *
     * @param  list<int>  $ids
     */
    public function lockForUpdate(array $ids): void;
}
