<?php

namespace App\Repositories\Contracts;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Collection;

interface StaffRepositoryInterface extends RepositoryInterface
{
    /**
     * Whether an active staff member other than $exceptId already holds $position in
     * shift $shiftId. Used to enforce "at most one active head/assistant_head per
     * shift". A locking read (see the Eloquent implementation for why).
     */
    public function hasActiveInPosition(int $shiftId, string $position, ?int $exceptId): bool;

    /**
     * @param  list<int>  $shiftIds
     */
    public function syncShifts(Staff $staff, array $shiftIds): void;

    /**
     * The ids of every shift $staff currently belongs to. A locking read.
     *
     * @return list<int>
     */
    public function shiftIdsFor(Staff $staff): array;

    /**
     * Whether $staff has any subject_assignments rows. Foreign keys don't protect
     * soft-deleted rows, so StaffService::delete() checks this explicitly.
     */
    public function hasSubjectAssignments(Staff $staff): bool;

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
     * @param  bool  $withFullProfile  Also eager-load educations/trainings, for pages
     *                                 that render each result's full profile inline
     *                                 (see Web\StaffController::renderInline()).
     */
    public function publicList(array $filters, bool $withFullProfile = false): Collection;

    public function findPublished(int $id): Staff;

    /**
     * Every published profile's id and updated_at, for the sitemap.
     */
    public function publishedForSitemap(): Collection;
}
