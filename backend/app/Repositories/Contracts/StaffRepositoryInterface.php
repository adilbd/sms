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
     * Whether $staff leads any section as class teacher. class_sections.staff_id is
     * `restrictOnDelete`, but foreign keys don't protect soft-deleted rows, so
     * StaffService::delete() checks this explicitly too.
     */
    public function isClassTeacher(Staff $staff): bool;

    /**
     * Whether $staff belongs to shift $shiftId. Used by ClassTeacherService to
     * enforce that a class teacher belongs to their section's shift.
     */
    public function belongsToShift(Staff $staff, int $shiftId): bool;

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
