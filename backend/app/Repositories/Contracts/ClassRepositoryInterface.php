<?php

namespace App\Repositories\Contracts;

use App\Models\Classes;

interface ClassRepositoryInterface extends RepositoryInterface
{
    public function hasSections(Classes $class): bool;

    public function hasStudents(Classes $class): bool;

    public function hasAttendances(Classes $class): bool;

    public function hasExamSchedules(Classes $class): bool;

    public function hasFeeStructures(Classes $class): bool;

    public function hasSubjectAssignments(Classes $class): bool;
}
