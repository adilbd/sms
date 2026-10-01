<?php

namespace App\Repositories\Contracts;

use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

interface StudentRepositoryInterface extends RepositoryInterface
{
    /**
     * The next free student ID for an admission year: the year followed by a 4-digit
     * sequence ("20260001"). Soft-deleted students keep their numbers.
     */
    public function nextStudentId(int $year): string;

    /**
     * Loads what the single-student responses need: the login, the full enrolment
     * history, and `currentEnrolment` constrained to $academicYearId (null when the
     * student has none in that year).
     */
    public function loadDetail(Student $student, ?int $academicYearId): Student;

    /**
     * The student whose own login is $user, with the detail relations for $academicYearId.
     */
    public function findByUser(User $user, ?int $academicYearId): ?Student;

    /**
     * The students whose guardian login is $guardian, with the detail relations for
     * $academicYearId, ordered by student ID.
     */
    public function childrenOf(User $guardian, ?int $academicYearId): Collection;

    /**
     * Whether $guardian still has a non-deleted student with status `active`.
     */
    public function hasActiveChildren(User $guardian): bool;

    public function hasAttendances(Student $student): bool;

    public function hasExamMarks(Student $student): bool;

    public function hasFeePayments(Student $student): bool;
}
