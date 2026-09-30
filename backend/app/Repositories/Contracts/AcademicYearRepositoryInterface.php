<?php

namespace App\Repositories\Contracts;

use App\Models\AcademicYear;

interface AcademicYearRepositoryInterface extends RepositoryInterface
{
    public function hasStudents(AcademicYear $academicYear): bool;

    public function hasExams(AcademicYear $academicYear): bool;

    public function hasFeeStructures(AcademicYear $academicYear): bool;

    public function hasClassTeacherRows(AcademicYear $academicYear): bool;

    public function hasSubjectAssignments(AcademicYear $academicYear): bool;

    /**
     * Deactivates every other year, inside AcademicYearService::activate()'s
     * transaction, so exactly one year stays active.
     */
    public function deactivateAllExcept(AcademicYear $academicYear): void;
}
