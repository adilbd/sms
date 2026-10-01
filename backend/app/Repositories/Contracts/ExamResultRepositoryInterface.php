<?php

namespace App\Repositories\Contracts;

use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\ExamSubject;
use App\Models\StudentEnrolment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * A narrower contract than RepositoryInterface: results are never edited one by one, an
 * exam's whole set is replaced when it is processed (see App\Services\ResultService).
 */
interface ExamResultRepositoryInterface
{
    /**
     * The active enrolments of the exam's academic year in $classId whose student has not
     * been deleted, with `student`, `class` and `section.shift` loaded, in section then roll
     * order.
     */
    public function activeEnrolments(Exam $exam, int $classId): Collection;

    /**
     * The ids of the enrolments (see activeEnrolments()) whose student takes $subject. This
     * is the same rule as the mark sheets (StudentEnrolment::scopeTakingSubject()).
     *
     * @return list<int>
     */
    public function subjectTakers(Exam $exam, ExamSubject $subject): array;

    /**
     * Every mark entered for the exam's subjects.
     */
    public function marksFor(Exam $exam): Collection;

    /**
     * Deletes every result of $exam and inserts $rows (one array per result, with
     * `subjects` as a PHP array).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function replaceForExam(Exam $exam, array $rows): void;

    /**
     * The exam's results in position order, with `student`, `enrolment`, `class` and
     * `section` loaded. Ordered by section position when a section is filtered, otherwise
     * by class then class position.
     *
     * @param  array{class_id?: mixed, section_id?: mixed}  $filters
     */
    public function paginateForExam(Exam $exam, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * One student's result in the exam with everything the breakdown and report card show,
     * or null when the exam has no result for the student.
     */
    public function findForStudent(Exam $exam, int $studentId): ?ExamResult;

    /**
     * The student's results in published exams, newest exam first, with the exam and
     * everything the breakdown shows.
     */
    public function publishedForStudent(int $studentId): Collection;

    /**
     * The subjects of the exams held for $classId in the academic year that are open to
     * students (not a draft), with `exam` and `subject` loaded, in exam then schedule order.
     */
    public function scheduleFor(int $classId, int $academicYearId): Collection;
}
