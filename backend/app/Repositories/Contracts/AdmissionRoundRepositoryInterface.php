<?php

namespace App\Repositories\Contracts;

use App\Models\AdmissionRound;
use Illuminate\Database\Eloquent\Collection;

interface AdmissionRoundRepositoryInterface extends RepositoryInterface
{
    /**
     * The rounds families can apply to today (published, inside their window), with their
     * classes and academic year, soonest closing first.
     */
    public function open(): Collection;

    /**
     * An open round by id, with its classes and academic year, or null (unknown, draft,
     * not yet open or closed).
     */
    public function findOpen(int $id): ?AdmissionRound;

    /**
     * Reloads the round with a row lock (inside the caller's transaction), so approvals
     * for its classes queue up and the seat count they check cannot go stale.
     */
    public function lockForUpdate(AdmissionRound $round): AdmissionRound;

    /**
     * The seats offered in the round's class, or null when the class has no limit or is
     * not part of the round.
     */
    public function seatsFor(AdmissionRound $round, int $classId): ?int;

    /**
     * Whether any application (soft-deleted included, as foreign keys still hold them)
     * belongs to the round.
     */
    public function hasApplications(AdmissionRound $round): bool;

    /**
     * Ids of the round's classes that have at least one application.
     *
     * @return list<int>
     */
    public function classIdsWithApplications(AdmissionRound $round): array;

    /**
     * Makes the round's classes exactly $classes (class_id and seats): creates, updates
     * and deletes rows.
     *
     * @param  list<array{class_id: int, seats?: ?int}>  $classes
     */
    public function syncClasses(AdmissionRound $round, array $classes): void;

    /**
     * Loads what the admin resource shows: academic year, classes with their class, and
     * the application count.
     */
    public function loadDetail(AdmissionRound $round): AdmissionRound;
}
