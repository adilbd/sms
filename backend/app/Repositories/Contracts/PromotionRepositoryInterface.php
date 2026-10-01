<?php

namespace App\Repositories\Contracts;

use App\Models\Classes;
use App\Models\Section;
use Illuminate\Database\Eloquent\Collection;

/**
 * A narrower contract than RepositoryInterface: promotion has no model of its own. It
 * reads enrolments and finds target classes and sections; the writes go through
 * StudentEnrolmentRepositoryInterface / EnrolmentService (see App\Services\PromotionService).
 */
interface PromotionRepositoryInterface
{
    /**
     * The active enrolments of the section in the academic year whose student has not
     * been deleted, with `student` and `optionalSubject` loaded, in roll order (no roll
     * last), then by id.
     */
    public function activeEnrolments(int $sectionId, int $academicYearId): Collection;

    /**
     * Which of $studentIds already have an enrolment (any status) in the academic year.
     *
     * @param  list<int>  $studentIds
     * @return list<int>
     */
    public function enrolledStudentIds(array $studentIds, int $academicYearId): array;

    public function findClassByNumber(int $number): ?Classes;

    /**
     * The active section of $classId with this code and shift, or null.
     */
    public function findSectionByCodeAndShift(int $classId, string $code, int $shiftId): ?Section;
}
