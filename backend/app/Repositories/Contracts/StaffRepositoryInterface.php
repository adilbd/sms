<?php

namespace App\Repositories\Contracts;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Collection;

interface StaffRepositoryInterface extends RepositoryInterface
{
    /**
     * Whether an active staff member other than $exceptId already holds $position in
     * shift $shiftId. Used to enforce "at most one active head/assistant_head per shift".
     */
    public function hasActiveInPosition(int $shiftId, string $position, ?int $exceptId): bool;

    /**
     * @param  list<int>  $shiftIds
     */
    public function syncShifts(Staff $staff, array $shiftIds): void;

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function syncEducations(Staff $staff, array $rows): void;

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function syncTrainings(Staff $staff, array $rows): void;

    /**
     * @param  array{position?: string, former?: bool, shift_id?: int}  $filters
     */
    public function publicList(array $filters): Collection;

    public function findPublished(int $id): Staff;

    /**
     * Every published profile's id and updated_at, for the sitemap.
     */
    public function publishedForSitemap(): Collection;
}
