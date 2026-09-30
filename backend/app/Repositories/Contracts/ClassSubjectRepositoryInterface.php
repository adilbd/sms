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
     * @param  list<array{subject_id: int, group: ?string, type: string}>  $rows
     */
    public function sync(Classes $class, array $rows): void;

    /**
     * Of $subjectIds, the ones that are inactive or soft-deleted and are not already
     * part of $class's curriculum (existing rows stay when a subject is deactivated).
     *
     * @param  list<int>  $subjectIds
     * @return list<int>
     */
    public function unusableSubjectIds(Classes $class, array $subjectIds): array;
}
