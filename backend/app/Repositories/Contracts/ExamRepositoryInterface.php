<?php

namespace App\Repositories\Contracts;

use App\Models\Classes;
use App\Models\Exam;
use App\Models\ExamSubject;
use Illuminate\Database\Eloquent\Collection;

interface ExamRepositoryInterface extends RepositoryInterface
{
    /**
     * Loads what a single-exam response shows: the academic year, the classes and the
     * subject count (route model binding bypasses the list query's eager loads).
     */
    public function loadDetail(Exam $exam): Exam;

    /**
     * Takes a row lock on $exam (`select ... for update`) and returns the freshly read row,
     * so concurrent writes to one exam run one after another and validate against its
     * committed state. Call inside a transaction. A soft-deleted exam is a 404.
     *
     * Lock order across the exam code: class rows first, then the section row, then the
     * exam row, then exam_subject rows, never the other way round.
     */
    public function lockExam(Exam $exam): Exam;

    /**
     * Takes a row lock on $subject and returns the fresh row. Call inside a transaction.
     */
    public function lockSubject(ExamSubject $subject): ExamSubject;

    /**
     * The classes with these ids, in ascending id order (the order to lock them in).
     *
     * @param  list<int>  $ids
     */
    public function classesByIds(array $ids): Collection;

    /**
     * The ids of the classes the exam has subjects for, ascending.
     *
     * @return list<int>
     */
    public function classIds(Exam $exam): array;

    /**
     * The exam's subjects with their subject and class, in class then curriculum order,
     * optionally for one class.
     */
    public function subjectsFor(Exam $exam, ?int $classId = null): Collection;

    /**
     * The subject with this id if it belongs to $exam (with its subject and class loaded),
     * otherwise null.
     */
    public function findSubject(Exam $exam, int $examSubjectId): ?ExamSubject;

    /**
     * Makes $class's subjects of $exam exactly $rows (the curriculum snapshot, one array per
     * subject with subject_id, group, type, paper_group, the six part fields and
     * sort_order): a (subject_id, group) pair that already exists is updated in place and
     * keeps its date and times, the rest are created and the others deleted.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function replaceClassSubjects(Exam $exam, Classes $class, array $rows): void;

    /**
     * Deletes $class's subjects of $exam.
     */
    public function deleteClassSubjects(Exam $exam, int $classId): void;

    /**
     * Deletes every subject of $exam.
     */
    public function deleteSubjects(Exam $exam): void;

    /**
     * Updates $subject and returns it with its subject and class loaded.
     */
    public function updateSubject(ExamSubject $subject, array $attributes): ExamSubject;

    public function hasMarks(Exam $exam): bool;

    public function hasResults(Exam $exam): bool;

    public function hasMarksForClass(Exam $exam, int $classId): bool;

    public function hasMarksForSubject(ExamSubject $subject): bool;
}
