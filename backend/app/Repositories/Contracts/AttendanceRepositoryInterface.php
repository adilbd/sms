<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

/**
 * A narrower contract than RepositoryInterface: attendance has no standalone CRUD
 * endpoint. It is read and written a section-day at a time through
 * App\Services\AttendanceService.
 */
interface AttendanceRepositoryInterface
{
    /**
     * Every enrolment of the section for the year, whatever its status (of students not
     * deleted), ordered by roll number, each with its student and its `attendances`
     * constrained to $date. Which of them were on the roll that day is the service's
     * decision (AttendanceService::onRoll()).
     */
    public function candidateEnrolments(int $sectionId, int $academicYearId, string $date): Collection;

    /**
     * Creates or updates one row per (enrolment, date), stamping `marked_by`. Rows for
     * enrolments not listed are left alone.
     *
     * @param  list<array{student_id: int, enrolment_id: int, status: string, remarks: ?string}>  $rows
     */
    public function saveRows(array $rows, int $sectionId, int $academicYearId, string $date, ?int $userId): void;

    /**
     * The section's enrolments for the year that belong on a report for $from..$to: the
     * active ones, plus any other enrolment (left, promoted) with attendance recorded in
     * that range. Each has its student and its section `attendances` in the range.
     */
    public function reportEnrolments(int $sectionId, int $academicYearId, string $from, string $to): Collection;

    /**
     * One student's attendance rows from $from to $to (`Y-m-d`, inclusive), oldest first.
     */
    public function recordsForStudent(int $studentId, string $from, string $to): Collection;

    /**
     * The latest date (`Y-m-d`) with an attendance row for the student, or null.
     */
    public function lastDateForStudent(int $studentId): ?string;
}
