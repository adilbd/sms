<?php

namespace App\Repositories\Contracts;

use App\Models\FeeRate;
use Illuminate\Database\Eloquent\Collection;

interface FeeRateRepositoryInterface extends RepositoryInterface
{
    /**
     * Whether another rate exists for the same head, class, year and group. A null group
     * matches only a null group (the unique index can't, since nulls are distinct).
     */
    public function existsFor(int $headId, int $classId, int $academicYearId, ?string $group, ?int $exceptId): bool;

    /**
     * Whether any fee due uses the rate's head in an enrolment of its class and year.
     */
    public function hasDues(FeeRate $rate): bool;

    /**
     * The year's rates of these heads for these classes (any group), for generating dues.
     *
     * @param  list<int>  $headIds
     * @param  list<int>  $classIds
     */
    public function forGeneration(int $academicYearId, array $headIds, array $classIds): Collection;

    /**
     * Loads what a single-rate response shows (route model binding bypasses the list query).
     */
    public function loadDetail(FeeRate $rate): FeeRate;
}
