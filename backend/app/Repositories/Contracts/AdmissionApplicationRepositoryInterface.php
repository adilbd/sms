<?php

namespace App\Repositories\Contracts;

use App\Models\AdmissionApplication;

interface AdmissionApplicationRepositoryInterface extends RepositoryInterface
{
    /**
     * Whether the round already has an application for this birth registration number
     * (soft-deleted included, as the unique index still holds them).
     */
    public function existsForBirthRegistration(int $roundId, string $birthRegistrationNumber): bool;

    /**
     * Issues the next number of the year's sequence. Call inside a transaction: the
     * counter row stays locked until it commits, so numbers are sequential and unique.
     */
    public function nextApplicationNumber(int $year): int;

    /**
     * The application with this number and date of birth (`Y-m-d`), with its round, class
     * and shift, or null. Both must match, so a number alone reveals nothing.
     */
    public function findForStatusLookup(string $applicationNo, string $dateOfBirth): ?AdmissionApplication;

    /**
     * The application by id with its round and class, or null.
     */
    public function findWithRoundAndClass(int $id): ?AdmissionApplication;

    /**
     * Reloads the application with a row lock (inside the caller's transaction).
     */
    public function lockForUpdate(AdmissionApplication $application): AdmissionApplication;

    /**
     * How many applications of the round's class hold a seat (approved or admitted),
     * leaving out $exceptId.
     */
    public function countSeatsTaken(int $roundId, int $classId, ?int $exceptId = null): int;

    /**
     * Applications per status for the same filters the list takes (the status filter
     * excluded), as [status => count] with every status present.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    public function countsByStatus(array $filters): array;

    /**
     * Loads what the admin resource shows: round, class, shift, student and the admin who
     * decided.
     */
    public function loadDetail(AdmissionApplication $application): AdmissionApplication;
}
