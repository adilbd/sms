<?php

namespace App\Repositories\Contracts;

use App\Models\StudentFeeWaiver;
use Illuminate\Database\Eloquent\Collection;

interface FeeWaiverRepositoryInterface extends RepositoryInterface
{
    /**
     * Whether the student already has a waiver on the head for the year.
     */
    public function existsFor(int $studentId, int $academicYearId, int $headId, ?int $exceptId): bool;

    /**
     * The year's waivers of these students, for generating dues.
     *
     * @param  list<int>  $studentIds
     */
    public function forGeneration(int $academicYearId, array $studentIds): Collection;

    /**
     * Loads what a single-waiver response shows (route model binding bypasses the list query).
     */
    public function loadDetail(StudentFeeWaiver $waiver): StudentFeeWaiver;
}
