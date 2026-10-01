<?php

namespace App\Repositories\Contracts;

use App\Models\AcademicYear;

interface AcademicYearRepositoryInterface extends RepositoryInterface
{
    /**
     * Whether any student is enrolled in the year (student_enrolments).
     */
    public function hasStudents(AcademicYear $academicYear): bool;

    /**
     * The one active academic year, or null when none is active yet.
     */
    public function findActive(): ?AcademicYear;

    public function hasExams(AcademicYear $academicYear): bool;

    /**
     * Whether the year has fee rates, or fee dues in any of its enrolments.
     */
    public function hasFeeRatesOrDues(AcademicYear $academicYear): bool;

    public function hasClassTeacherRows(AcademicYear $academicYear): bool;

    public function hasSubjectAssignments(AcademicYear $academicYear): bool;

    public function hasHolidays(AcademicYear $academicYear): bool;

    /**
     * The academic year for a calendar year (`year` is unique), or null when none exists.
     */
    public function findByYear(int $year): ?AcademicYear;

    /**
     * Deactivates every other year, inside AcademicYearService::activate()'s
     * transaction, so exactly one year stays active.
     */
    public function deactivateAllExcept(AcademicYear $academicYear): void;
}
