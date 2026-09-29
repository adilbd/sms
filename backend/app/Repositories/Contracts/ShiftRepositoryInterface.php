<?php

namespace App\Repositories\Contracts;

use App\Models\Shift;
use Illuminate\Database\Eloquent\Collection;

interface ShiftRepositoryInterface extends RepositoryInterface
{
    public function hasStaff(Shift $shift): bool;

    /**
     * Active shifts, ordered for display. Used to decide whether the public shift
     * filter pills should be shown at all (only when there is more than one).
     */
    public function activeOrdered(): Collection;

    public function findBySlug(string $slug): ?Shift;
}
