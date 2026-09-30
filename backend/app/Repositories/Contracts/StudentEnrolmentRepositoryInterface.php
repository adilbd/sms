<?php

namespace App\Repositories\Contracts;

use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrolment;
use Illuminate\Database\Eloquent\Collection;

/**
 * A narrower contract than RepositoryInterface: enrolments have no standalone CRUD
 * endpoint and are only written through App\Services\EnrolmentService.
 */
interface StudentEnrolmentRepositoryInterface
{
    /**
     * Takes a row lock on the section (`select ... for update`) and returns the freshly
     * read row with its class and shift, so concurrent enrolments into the same section
     * run one after another and each sees the other's committed seat. Call inside a
     * transaction.
     */
    public function lockSection(int $sectionId): Section;

    public function forStudentAndYear(Student $student, int $academicYearId): ?StudentEnrolment;

    /**
     * Whether another enrolment of the section already has this roll number that year.
     */
    public function rollNumberTaken(int $sectionId, int $academicYearId, int $rollNumber, ?int $exceptId): bool;

    /**
     * How many active enrolments the section has that year (the ones taking a seat).
     */
    public function countActiveInSection(int $sectionId, int $academicYearId): int;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): StudentEnrolment;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(StudentEnrolment $enrolment, array $attributes): StudentEnrolment;

    /**
     * Every enrolment of the student, newest year first, with its year, class, section
     * and 4th subject.
     */
    public function historyFor(Student $student): Collection;
}
