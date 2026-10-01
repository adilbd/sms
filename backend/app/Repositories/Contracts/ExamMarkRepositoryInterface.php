<?php

namespace App\Repositories\Contracts;

use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\Section;
use Illuminate\Database\Eloquent\Collection;

/**
 * A narrower contract than RepositoryInterface: marks have no standalone CRUD endpoint, a
 * mark sheet is always read and saved as a whole (see App\Services\ExamMarkService).
 */
interface ExamMarkRepositoryInterface
{
    /**
     * Takes a row lock on section $sectionId and returns the fresh row, so two saves for the
     * same section queue up. Call inside a transaction, after nothing else is locked
     * (see ExamRepositoryInterface::lockExam() for the lock order).
     */
    public function lockSection(int $sectionId): Section;

    /**
     * The mark sheet of $subject for $section: the active enrolments of the exam's academic
     * year in the section whose student takes the subject, each with `student` and its
     * `examMarks` for this subject loaded, in roll order. A student takes the subject when
     * it is compulsory and common to the class (no group) or for the student's group, or
     * when it is optional and is the student's chosen 4th subject (and, for a group's row,
     * the student is in that group).
     */
    public function sheetEnrolments(Exam $exam, Section $section, ExamSubject $subject): Collection;

    /**
     * Upserts one mark per row for $subject: `student_id`, `enrolment_id`, `written`, `mcq`,
     * `practical` and `is_absent`, entered by user $userId. A row that carries nothing
     * (every part null, not absent) deletes the student's saved mark instead, so a blank
     * sheet never counts as "marks exist".
     *
     * @param  list<array{student_id: int, enrolment_id: int, written: mixed, mcq: mixed, practical: mixed, is_absent: bool}>  $rows
     */
    public function saveRows(ExamSubject $subject, array $rows, ?int $userId): void;
}
