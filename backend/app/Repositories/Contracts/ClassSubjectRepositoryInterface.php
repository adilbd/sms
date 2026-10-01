<?php

namespace App\Repositories\Contracts;

use App\Models\Classes;
use Illuminate\Database\Eloquent\Collection;

/**
 * A narrower contract than RepositoryInterface: class_subjects has no standalone CRUD
 * endpoint (see App\Services\CurriculumService, used only through ClassController's
 * curriculum actions), and a curriculum is always read and replaced as a whole.
 */
interface ClassSubjectRepositoryInterface
{
    /**
     * Every curriculum row of $class with its subject, in curriculum order.
     */
    public function forClass(Classes $class): Collection;

    /**
     * The effective list for $group: the class-wide rows (group null) plus the rows of
     * that group, with subjects, in curriculum order.
     */
    public function forClassAndGroup(Classes $class, string $group): Collection;

    /**
     * Replaces $class's curriculum with $rows in one pass: rows not listed are deleted,
     * a (subject_id, group) pair that already exists is updated in place and the rest
     * are created. Array position becomes `sort_order`.
     *
     * A row may carry the marks scheme. Only the keys present are applied: with none of the
     * six part fields a saved row keeps its marks and a new row takes its subject's
     * total/pass marks as the written part; with `paper_group` absent a saved row keeps its
     * pairing. Any part field present sets all six (a missing one becomes null).
     *
     * @param  list<array<string, mixed>>  $rows  subject_id, group, type and optionally the marks fields
     */
    public function sync(Classes $class, array $rows): void;

    /**
     * Takes a row lock on $class (`select ... for update`), so concurrent curriculum
     * replacements for the same class run one after another. Call inside a transaction.
     * Returns the freshly read row, so callers validate against its committed state
     * rather than a stale route-bound model.
     */
    public function lockClass(Classes $class): Classes;

    /**
     * The indexes of the $rows whose subject is inactive or soft-deleted and that are not
     * an existing (subject_id, group) row of $class's curriculum. A row already in the
     * curriculum stays when its subject is deactivated later; the same subject newly added
     * under another group does not.
     *
     * @param  list<array{subject_id: int, group: ?string, type: string}>  $rows
     * @return list<int>
     */
    public function unusableRowIndexes(Classes $class, array $rows): array;

    /**
     * The saved `paper_group` of each curriculum row of $class, keyed `{subject_id}|{group}`
     * (group empty for a class-wide row). Rows without a pairing are left out.
     *
     * @return array<string, string>
     */
    public function savedPaperGroups(Classes $class): array;

    /**
     * The subject-teacher assignments of $class that still matter, one entry per distinct
     * (subject, section group): only the active academic year and later ones count (earlier
     * years are history), and every year counts when none is active. `group` is the
     * assigned section's group (null for a section without one). Removing the curriculum
     * row an assignment relies on must be refused, since the assignment would point at a
     * subject the section's class and group no longer study.
     *
     * @return list<array{subject_id: int, name: string, group: ?string}>
     */
    public function assignedSubjects(Classes $class): array;
}
