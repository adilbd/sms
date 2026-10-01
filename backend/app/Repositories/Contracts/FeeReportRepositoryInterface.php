<?php

namespace App\Repositories\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only queries behind the fee reports and the student's own fee page. Not a CRUD
 * repository, so it doesn't extend RepositoryInterface.
 */
interface FeeReportRepositoryInterface
{
    /**
     * The dues of the section's enrolments in the year, with head and the student and
     * enrolment, optionally only those falling due in the month (`YYYY-MM`).
     */
    public function duesInSection(int $sectionId, int $academicYearId, ?string $month): Collection;

    /**
     * Payments paid at or after $from and before $to (UTC), with student and collector,
     * oldest first; cancelled ones included (flagged by cancelled_at).
     *
     * @param  array{method?: ?string, collected_by?: ?int}  $filters
     */
    public function paymentsBetween(CarbonInterface $from, CarbonInterface $to, array $filters = []): Collection;

    /**
     * The student's dues in the year with their head, oldest due date first.
     */
    public function duesOfStudentInYear(int $studentId, int $academicYearId): Collection;

    /**
     * The allocations to the student's dues of the year, with their payment.
     */
    public function allocationsOfStudentInYear(int $studentId, int $academicYearId): Collection;

    /**
     * Every payment of the student, newest first, cancelled ones included.
     */
    public function paymentsOfStudent(int $studentId): Collection;
}
