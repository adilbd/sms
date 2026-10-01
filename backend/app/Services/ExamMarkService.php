<?php

namespace App\Services;

use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\Section;
use App\Models\User;
use App\Repositories\Contracts\ExamMarkRepositoryInterface;
use App\Repositories\Contracts\ExamRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mark entry by section and subject, part by part. Only an admin or the subject's assigned
 * teacher may read or save a sheet (SubjectAssignmentService::canEnterMarks()). Saving
 * takes the section lock, then the exam lock, then the subject lock (the lock order in
 * ExamRepositoryInterface::lockExam()), and upserts the whole sheet in one transaction.
 */
class ExamMarkService
{
    /** Exam statuses that accept marks. Task 3 adds `processed` once results exist. */
    private const ENTRY_STATUSES = [Exam::STATUS_MARKS_ENTRY];

    public function __construct(
        private ExamRepositoryInterface $exams,
        private ExamMarkRepositoryInterface $marks,
        private SectionRepositoryInterface $sections,
        private SubjectAssignmentService $assignments,
    ) {}

    /**
     * The sheet for one section and subject.
     *
     * @return array{exam_subject: ExamSubject, section: Section, enrolments: \Illuminate\Database\Eloquent\Collection}
     */
    public function sheet(User $user, Exam $exam, int $sectionId, int $examSubjectId): array
    {
        [$subject, $section] = $this->resolve($user, $exam, $sectionId, $examSubjectId);

        return $this->build($exam, $section, $subject);
    }

    /**
     * Saves the whole sheet and returns it as saved. Errors are keyed per row
     * (`marks.3.mcq`). 403 without the assignment, 409 unless the exam is in mark entry.
     *
     * @param  array{section_id: int, exam_subject_id: int, marks: list<array<string, mixed>>}  $data
     * @return array{exam_subject: ExamSubject, section: Section, enrolments: \Illuminate\Database\Eloquent\Collection}
     */
    public function save(User $user, Exam $exam, array $data): array
    {
        [$subject, $section] = $this->resolve($user, $exam, (int) $data['section_id'], (int) $data['exam_subject_id']);

        DB::transaction(function () use ($user, $exam, $section, $subject, $data) {
            $lockedSection = $this->marks->lockSection($section->id);
            $lockedExam = $this->exams->lockExam($exam);

            abort_unless(
                in_array($lockedExam->status, self::ENTRY_STATUSES, true),
                409,
                $lockedExam->status === Exam::STATUS_DRAFT
                    ? 'Mark entry has not been opened for this exam yet.'
                    : 'Marks can no longer be entered for this exam.'
            );

            $lockedSubject = $this->exams->lockSubject($subject);
            $enrolments = $this->marks->sheetEnrolments($lockedExam, $lockedSection, $lockedSubject)->keyBy('student_id');

            $rows = $this->validatedRows($lockedSubject, $enrolments, array_values($data['marks']));

            $this->marks->saveRows($lockedSubject, $rows, $user->id);
        });

        return $this->build($exam, $section, $subject);
    }

    /**
     * The exam subject (of this exam) and section (of its class) for a sheet request, after
     * the authorization rule: an admin or the assigned subject teacher.
     *
     * @return array{ExamSubject, Section}
     */
    private function resolve(User $user, Exam $exam, int $sectionId, int $examSubjectId): array
    {
        $subject = $this->exams->findSubject($exam, $examSubjectId);

        if (! $subject) {
            throw ValidationException::withMessages(['exam_subject_id' => ['This subject is not part of the exam.']]);
        }

        $section = $this->sections->findOrFail($sectionId);

        if ((int) $section->class_id !== (int) $subject->class_id) {
            throw ValidationException::withMessages(['section_id' => ["The section is not in this subject's class."]]);
        }

        $exam->loadMissing('academicYear');

        abort_unless(
            $this->assignments->canEnterMarks($user, $section, $subject->subject, $exam->academicYear),
            403,
            'Only the assigned subject teacher or an admin can enter marks.'
        );

        return [$subject, $section];
    }

    /**
     * @return array{exam_subject: ExamSubject, section: Section, enrolments: \Illuminate\Database\Eloquent\Collection}
     */
    private function build(Exam $exam, Section $section, ExamSubject $subject): array
    {
        return [
            'exam_subject' => $subject,
            'section' => $section,
            'enrolments' => $this->marks->sheetEnrolments($exam, $section, $subject),
        ];
    }

    /**
     * Checks every row against the sheet and the subject's parts, collecting errors per row
     * so nothing is written when any row fails, and returns the rows ready to save.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\StudentEnrolment>  $enrolments  keyed by student id
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{student_id: int, enrolment_id: int, written: mixed, mcq: mixed, practical: mixed, is_absent: bool}>
     */
    private function validatedRows(ExamSubject $subject, $enrolments, array $rows): array
    {
        $errors = [];
        $valid = [];

        foreach ($rows as $i => $row) {
            $studentId = (int) $row['student_id'];
            $enrolment = $enrolments->get($studentId);
            $absent = (bool) ($row['is_absent'] ?? false);
            $values = [];

            if (! $enrolment) {
                $errors["marks.{$i}.student_id"][] = 'This student is not on the mark sheet for this subject and section.';

                continue;
            }

            foreach (ClassSubject::PARTS as $part) {
                $value = $row[$part] ?? null;
                $value = $value === '' ? null : $value;
                $full = $subject->{"{$part}_full"};
                $values[$part] = $value;

                if ($value === null) {
                    continue;
                }

                if ($full === null) {
                    $errors["marks.{$i}.{$part}"][] = "This subject has no {$part} part, so it must be left empty.";
                } elseif ((float) $value < 0 || (float) $value > $full) {
                    $errors["marks.{$i}.{$part}"][] = "The {$part} marks must be between 0 and {$full}.";
                }
            }

            if ($absent && array_filter($values, fn ($value) => $value !== null) !== []) {
                $errors["marks.{$i}.is_absent"][] = 'An absent student cannot have marks. Clear the marks or untick absent.';
            }

            $valid[] = [
                'student_id' => $studentId,
                'enrolment_id' => $enrolment->id,
                'written' => $values['written'],
                'mcq' => $values['mcq'],
                'practical' => $values['practical'],
                'is_absent' => $absent,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $valid;
    }
}
