<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Collection;

interface FeeDueRepositoryInterface extends RepositoryInterface
{
    /**
     * The (enrolment, head, period) combinations that already have a due, as
     * `"{enrolment_id}|{fee_head_id}|{period}"` keys.
     *
     * @param  list<int>  $enrolmentIds
     * @param  list<int>  $headIds
     * @param  list<string>  $periods
     * @return array<string, true>
     */
    public function existingKeys(array $enrolmentIds, array $headIds, array $periods): array;

    /**
     * Inserts the rows, silently skipping any whose (enrolment, head, period) already
     * exists (a concurrent generation), and returns how many were inserted.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function insertMany(array $rows): int;

    /**
     * The student's dues that still owe money (unpaid or partial), oldest due date first.
     */
    public function openForStudent(int $studentId): Collection;

    /**
     * The student's open dues that have fallen due by the end of $month (`YYYY-MM`): monthly
     * dues whose period is on or before it, and dues without a month (`one_time`, `exam:*`)
     * whose due date is on or before its last day. Later months' dues are left out.
     */
    public function openDueByMonth(int $studentId, string $month): Collection;

    /**
     * The student's open dues with these ids, in the order of $ids. A due that is not the
     * student's, or is already paid or waived, is left out.
     *
     * @param  list<int>  $ids
     */
    public function openByIdsForStudent(int $studentId, array $ids): Collection;

    /**
     * Every due of the student with its head, oldest due date first.
     */
    public function allForStudent(int $studentId): Collection;
}
