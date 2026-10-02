<?php

namespace App\Repositories\Contracts;

use App\Models\Period;
use Illuminate\Database\Eloquent\Collection;

interface PeriodRepositoryInterface extends RepositoryInterface
{
    /**
     * A period of $shiftId whose time range overlaps $start-$end (`H:i:s`), or null. Two
     * ranges overlap when each starts before the other ends, so a period that starts exactly
     * when another ends is fine. $exceptId leaves one period out (the one being updated).
     */
    public function findOverlapping(int $shiftId, string $start, string $end, ?int $exceptId = null): ?Period;

    /**
     * Whether any routine slot (of any section or year) uses the period.
     */
    public function isUsedInRoutine(Period $period): bool;

    /**
     * Every period of the shift in number order.
     *
     * @return Collection<int, Period>
     */
    public function forShift(int $shiftId): Collection;

    /**
     * Takes a row lock on the period (`select ... for update`) and returns the fresh row.
     * Call inside a transaction; a no-op lock on SQLite.
     */
    public function lockForUpdate(Period $period): Period;
}
