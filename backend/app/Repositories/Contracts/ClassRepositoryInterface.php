<?php

namespace App\Repositories\Contracts;

use App\Models\Classes;

interface ClassRepositoryInterface extends RepositoryInterface
{
    /**
     * Takes a row lock on $class (`select ... for update`); call inside a transaction.
     * Returns the freshly read row, which callers use instead of a possibly stale model.
     */
    public function lockForUpdate(Classes $class): Classes;

    public function hasSections(Classes $class): bool;

    /**
     * Whether any (non-deleted) section of $class carries a group.
     */
    public function hasGroupedSections(Classes $class): bool;

    public function hasStudents(Classes $class): bool;

    public function hasAttendances(Classes $class): bool;

    public function hasExamSchedules(Classes $class): bool;

    public function hasFeeStructures(Classes $class): bool;

    public function hasSubjectAssignments(Classes $class): bool;

    /**
     * Whether $class's curriculum has a group row or an optional row, neither of which
     * a class below Class 9 can have.
     */
    public function hasGroupedCurriculum(Classes $class): bool;

    /**
     * Removes every curriculum row of $class, e.g. as part of ClassService::delete()'s
     * transaction.
     */
    public function deleteCurriculum(Classes $class): void;
}
