<?php

namespace App\Services;

use App\Exceptions\ResultNotFoundException;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\ExamSubject;
use App\Models\StudentEnrolment;
use App\Models\User;
use App\Repositories\Contracts\ExamRepositoryInterface;
use App\Repositories\Contracts\ExamResultRepositoryInterface;
use App\Support\Gpa;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Turns an exam's marks into results (GPA, grades, merit positions), publishes them, and
 * reads them back: the admin tabulation and breakdown, and a student's or guardian's own
 * published results. The grading rules are App\Support\Gpa; this class decides who takes
 * which subject, which papers form one unit and what counts as missing.
 *
 * Status: marks_entry/processed --process--> processed --publish--> published, and
 * unpublish goes back to processed, and reopen (processed only, clearing the results)
 * goes back to marks_entry. Saving marks (ExamMarkService) moves a processed exam
 * back to marks_entry, so stale results can't be published. Everything that changes the
 * status takes the exam lock first (the lock order in ExamRepositoryInterface::lockExam()).
 */
class ResultService
{
    /** Failed public lookups an hour per IP. Successful ones never count. */
    public const MAX_PUBLIC_FAILURES = 30;

    public function __construct(
        private ExamRepositoryInterface $exams,
        private ExamResultRepositoryInterface $results,
        private StudentService $students,
    ) {}

    /**
     * Recomputes every active enrolment of every class in the exam, replaces the exam's
     * results and marks the exam processed. 409 for a draft or published exam.
     *
     * Marks that are missing count as absent (an F): a paper with no mark row, or a mark
     * row that is not absent but leaves one of the paper's parts empty. The summary reports
     * how many such papers there are, per class and section, so the admin sees what is still
     * to be entered.
     *
     * @return array{exam: Exam, summary: list<array{class_id: int, class_name: string, section_id: int, section_name: string, students: int, passed: int, failed: int, missing_marks: int}>}
     */
    public function process(Exam $exam): array
    {
        $summary = [];

        $processed = DB::transaction(function () use ($exam, &$summary) {
            $locked = $this->exams->lockExam($exam);

            abort_if($locked->status === Exam::STATUS_PUBLISHED, 409, 'These results are published. Unpublish the exam before processing it again.');
            abort_if($locked->status === Exam::STATUS_DRAFT, 409, 'Open mark entry for this exam before processing it.');

            $marks = $this->results->marksFor($locked)
                ->keyBy(fn (ExamMark $mark) => $mark->exam_subject_id.'|'.$mark->student_id);

            $rows = [];

            foreach ($this->exams->classIds($locked) as $classId) {
                $classRows = $this->processClass($locked, $classId, $marks);

                $this->assignPositions($classRows);

                foreach ($classRows as $row) {
                    $rows[] = $row['result'];
                    $summary = $this->tally($summary, $row);
                }
            }

            $this->results->replaceForExam($locked, $rows);

            return $this->exams->update($locked, ['status' => Exam::STATUS_PROCESSED]);
        });

        return ['exam' => $this->exams->loadDetail($processed), 'summary' => array_values($summary)];
    }

    /**
     * processed → published. 409 from any other status.
     */
    public function publish(Exam $exam): Exam
    {
        $published = DB::transaction(function () use ($exam) {
            $locked = $this->exams->lockExam($exam);

            abort_if($locked->status === Exam::STATUS_PUBLISHED, 409, 'These results are already published.');
            abort_unless(
                $locked->status === Exam::STATUS_PROCESSED,
                409,
                'Process the results before publishing them. Marks may have changed since they were last processed.'
            );

            return $this->exams->update($locked, ['status' => Exam::STATUS_PUBLISHED, 'published_at' => now()]);
        });

        return $this->exams->loadDetail($published);
    }

    /**
     * published → processed. 409 from any other status.
     */
    public function unpublish(Exam $exam): Exam
    {
        $unpublished = DB::transaction(function () use ($exam) {
            $locked = $this->exams->lockExam($exam);

            abort_unless($locked->status === Exam::STATUS_PUBLISHED, 409, 'These results are not published.');

            return $this->exams->update($locked, ['status' => Exam::STATUS_PROCESSED, 'published_at' => null]);
        });

        return $this->exams->loadDetail($unpublished);
    }

    /**
     * processed → marks_entry, the explicit way back to editing marks and classes. Deletes
     * the exam's results (under the exam lock, before the status changes) so stale results
     * can't linger. 409 from any other status; a published exam must be unpublished first.
     */
    public function reopen(Exam $exam): Exam
    {
        $reopened = DB::transaction(function () use ($exam) {
            $locked = $this->exams->lockExam($exam);

            abort_if($locked->status === Exam::STATUS_PUBLISHED, 409, 'Unpublish the results before reopening mark entry.');
            abort_unless($locked->status === Exam::STATUS_PROCESSED, 409, 'Only an exam with processed results can be reopened for mark entry.');

            $this->results->deleteForExam($locked);

            return $this->exams->update($locked, ['status' => Exam::STATUS_MARKS_ENTRY]);
        });

        return $this->exams->loadDetail($reopened);
    }

    /**
     * The tabulation, in position order.
     *
     * @param  array{class_id?: mixed, section_id?: mixed}  $filters
     */
    public function tabulation(Exam $exam, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->results->paginateForExam($exam, $filters, $perPage);
    }

    /**
     * One student's result in the exam, with the breakdown. 404 when there is none.
     */
    public function breakdown(Exam $exam, int $studentId): ExamResult
    {
        $result = $this->results->findForStudent($exam, $studentId);

        abort_if($result === null, 404, 'Record not found.');

        return $result;
    }

    /**
     * The published exams for the public result pages, newest year first. Never a draft,
     * marks-entry or processed exam.
     */
    public function publishedExams(): Collection
    {
        return $this->results->publishedExams();
    }

    /**
     * The public lookup behind the website and /api/public/results: one published exam's
     * result for the student who matches the date of birth and either the student ID or the
     * section, group and roll. Whatever is wrong (the exam isn't published, the ID or roll
     * is unknown, the student wasn't enrolled that year, the date of birth differs) the
     * answer is the same ResultNotFoundException, so nothing here tells a visitor what
     * exists. Each failed lookup counts toward the IP's hourly limit (like AuthService
     * counts failed passwords); a successful one doesn't, and a locked IP gets a 429 with
     * Retry-After before anything is looked up.
     *
     * @param  array{exam_id: int|string, date_of_birth: string, student_id?: ?string, section_id?: int|string|null, group?: ?string, roll?: int|string|null}  $input
     *
     * @throws ValidationException when the group doesn't fit the section's class (needed from Class 9, not allowed below)
     */
    public function publicLookup(array $input, string $ip): ExamResult
    {
        $key = self::publicFailureKey($ip);

        if (RateLimiter::tooManyAttempts($key, self::MAX_PUBLIC_FAILURES)) {
            throw new ThrottleRequestsException(
                'Too many lookups. Please try again later.',
                null,
                ['Retry-After' => (string) RateLimiter::availableIn($key)],
            );
        }

        $criteria = filled($input['student_id'] ?? null)
            ? ['student_code' => (string) $input['student_id']]
            : $this->rollCriteria($input);

        $exam = $this->results->findPublishedExam((int) $input['exam_id']);
        $result = ($exam !== null && $criteria !== null)
            ? $this->results->findForPublicLookup($exam, $criteria, $input['date_of_birth'])
            : null;

        if ($result === null) {
            RateLimiter::hit($key, 3600);

            throw new ResultNotFoundException;
        }

        return $result;
    }

    public static function publicFailureKey(string $ip): string
    {
        return 'result-lookup-fail:'.sha1($ip);
    }

    /**
     * The section/group/roll criteria, or null when the section doesn't exist. The group is
     * required for a class that has groups and not allowed for one that hasn't (checked
     * against the section's class, so the form request can't know it).
     *
     * @return array{section_id: int, group: ?string, roll_number: int}|null
     */
    private function rollCriteria(array $input): ?array
    {
        $section = $this->results->findSectionWithClass((int) ($input['section_id'] ?? 0));

        if ($section === null) {
            return null;
        }

        $group = filled($input['group'] ?? null) ? (string) $input['group'] : null;
        $hasGroups = $section->class->hasGroups();

        if ($hasGroups && $group === null) {
            throw ValidationException::withMessages(['group' => ['Choose a group for this class.']]);
        }

        if (! $hasGroups && $group !== null) {
            throw ValidationException::withMessages(['group' => ['This class has no groups.']]);
        }

        return ['section_id' => $section->id, 'group' => $group, 'roll_number' => (int) $input['roll']];
    }

    /**
     * The signed-in student's own results in published exams, newest first.
     */
    public function ownResults(User $student): Collection
    {
        return $this->results->publishedForStudent($this->students->findOwn($student)->id);
    }

    /**
     * A child's published results for their guardian. 403 unless the student is the
     * guardian's own child.
     */
    public function childResults(User $guardian, int $studentId): Collection
    {
        return $this->results->publishedForStudent($this->students->findChildOf($guardian, $studentId)->id);
    }

    /**
     * The exam schedule (subject, date and time only) of the exams open to students for the
     * caller's class, or for each of the guardian's children's classes: one entry per
     * student with that student's exams, each holding only the subjects the student takes.
     * Draft exams are not shown. A student without an enrolment in the active year has no
     * exams.
     *
     * @return list<array{student: \App\Models\Student, exams: list<array{exam: Exam, subjects: list<ExamSubject>}>}>
     */
    public function ownSchedule(User $user): array
    {
        $students = $user->hasRole('student')
            ? [$this->students->findOwn($user)]
            : $this->students->childrenOf($user)->all();

        return array_map(function ($student) {
            $enrolment = $student->currentEnrolment;
            $exams = [];

            if ($enrolment) {
                $subjects = $this->results->scheduleFor($enrolment->class_id, $enrolment->academic_year_id)
                    ->filter(fn (ExamSubject $subject) => $enrolment->takes($subject));

                foreach ($subjects->groupBy('exam_id') as $examSubjects) {
                    $exams[] = ['exam' => $examSubjects->first()->exam, 'subjects' => $examSubjects->values()->all()];
                }
            }

            return ['student' => $student, 'exams' => $exams];
        }, $students);
    }

    /**
     * Grades every active enrolment of one class.
     *
     * @param  \Illuminate\Support\Collection<string, ExamMark>  $marks  keyed `examSubjectId|studentId`
     * @return list<array{enrolment: StudentEnrolment, missing: int, result: array<string, mixed>}>
     */
    private function processClass(Exam $exam, int $classId, $marks): array
    {
        $subjects = $this->exams->subjectsFor($exam, $classId);
        $takers = [];

        foreach ($subjects as $subject) {
            $takers[$subject->id] = array_flip($this->results->subjectTakers($exam, $subject));
        }

        $rows = [];

        foreach ($this->results->activeEnrolments($exam, $classId) as $enrolment) {
            $taken = $subjects->filter(fn (ExamSubject $subject) => isset($takers[$subject->id][$enrolment->id]));
            $rows[] = $this->gradeEnrolment($exam, $enrolment, $taken, $marks);
        }

        return $rows;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, ExamSubject>  $taken  the subjects the student takes
     * @param  \Illuminate\Support\Collection<string, ExamMark>  $marks
     * @return array{enrolment: StudentEnrolment, missing: int, result: array<string, mixed>}
     */
    private function gradeEnrolment(Exam $exam, StudentEnrolment $enrolment, $taken, $marks): array
    {
        $missing = 0;
        $entries = [];

        foreach ([ClassSubject::TYPE_COMPULSORY, ClassSubject::TYPE_OPTIONAL] as $type) {
            foreach ($this->units($taken->where('type', $type)) as $unit) {
                $entry = $this->gradeUnit($unit, $enrolment->student_id, $marks, $type === ClassSubject::TYPE_OPTIONAL);
                $missing += $entry['missing_papers'];
                unset($entry['missing_papers']);
                $entries[] = $entry;
            }
        }

        $compulsory = array_values(array_filter($entries, fn (array $entry) => ! $entry['is_optional']));
        // At most one optional unit exists: an enrolment has a single optional_subject_id.
        $optional = collect($entries)->firstWhere('is_optional', true);
        $outcome = Gpa::result($compulsory, $optional);

        return [
            'enrolment' => $enrolment,
            'missing' => $missing,
            'result' => [
                'student_id' => $enrolment->student_id,
                'enrolment_id' => $enrolment->id,
                'class_id' => $enrolment->class_id,
                'section_id' => $enrolment->section_id,
                'total_obtained' => $outcome['total_obtained'],
                'total_full' => $outcome['total_full'],
                'gpa' => $outcome['gpa'],
                'grade' => $outcome['grade'],
                'is_pass' => $outcome['is_pass'],
                'failed_count' => $outcome['failed_count'],
                'passed_count' => $outcome['passed_count'],
                'class_position' => null,
                'section_position' => null,
                'subjects' => $entries,
            ],
        ];
    }

    /**
     * Groups a student's subjects into graded units: rows sharing a paper_group are one
     * combined unit (a pair), everything else, including a paper_group with only one row
     * among the student's subjects, stands alone. Units keep the schedule order.
     *
     * @param  \Illuminate\Support\Collection<int, ExamSubject>  $subjects
     * @return list<list<ExamSubject>>
     */
    private function units($subjects): array
    {
        $units = [];

        foreach ($subjects as $subject) {
            if ($subject->paper_group === null) {
                $units[] = [$subject];

                continue;
            }

            $units['pg:'.$subject->paper_group][] = $subject;
        }

        return array_values($units);
    }

    /**
     * Grades one unit (one or two papers) for a student and returns its breakdown entry,
     * plus `missing_papers` (removed by the caller).
     *
     * @param  list<ExamSubject>  $unit
     * @param  \Illuminate\Support\Collection<string, ExamMark>  $marks
     * @return array<string, mixed>
     */
    private function gradeUnit(array $unit, int $studentId, $marks, bool $optional): array
    {
        $papers = [];
        $details = [];
        $missing = 0;

        foreach ($unit as $subject) {
            $mark = $marks->get($subject->id.'|'.$studentId);
            $parts = [];
            $incomplete = $mark === null;

            foreach ($subject->parts() as $part) {
                $parts[$part] = ['obtained' => $mark?->{$part}, 'full' => $subject->{"{$part}_full"}, 'pass' => $subject->{"{$part}_pass"}];

                if (! $mark?->is_absent && $mark !== null && $mark->{$part} === null) {
                    $incomplete = true;
                }
            }

            // A missing paper is graded as absent.
            $absent = $incomplete || (bool) $mark?->is_absent;
            $missing += $incomplete ? 1 : 0;

            $papers[] = ['absent' => $absent, 'parts' => $parts];
            $details[] = [
                'subject_id' => $subject->subject_id,
                'name_en' => $subject->subject?->name,
                'name_bn' => $subject->subject?->name_bn,
                'is_absent' => $absent,
                'is_missing' => $incomplete,
                'parts' => array_map(fn (array $part) => [
                    'obtained' => $part['obtained'] === null || $absent ? null : number_format((float) $part['obtained'], 2, '.', ''),
                    'full' => $part['full'],
                    'pass' => $part['pass'],
                ], $parts),
            ];
        }

        $graded = Gpa::unit($papers);

        return [
            'exam_subject_ids' => array_map(fn (ExamSubject $subject) => $subject->id, $unit),
            'subject_ids' => array_map(fn (ExamSubject $subject) => $subject->subject_id, $unit),
            'name_en' => $this->joinNames(array_column($details, 'name_en')),
            'name_bn' => $this->joinNames(array_column($details, 'name_bn')),
            'is_optional' => $optional,
            'is_combined' => count($unit) > 1,
            'papers' => $details,
            'parts' => $graded['parts'],
            'obtained' => $graded['obtained'],
            'full' => $graded['full'],
            'percentage' => $graded['percentage'],
            'grade' => $graded['grade'],
            'point' => $graded['point'],
            'is_absent' => $graded['is_absent'],
            'missing_papers' => $missing,
        ];
    }

    /**
     * @param  list<?string>  $names
     */
    private function joinNames(array $names): ?string
    {
        $names = array_values(array_filter($names, fn (?string $name) => filled($name)));

        return $names === [] ? null : implode(' + ', $names);
    }

    /**
     * Sets the class and section positions on a class's rows, each ranked within its own
     * group (see Gpa::positions()).
     *
     * @param  list<array{enrolment: StudentEnrolment, missing: int, result: array<string, mixed>}>  $rows
     */
    private function assignPositions(array &$rows): void
    {
        $rank = fn (array $subset) => Gpa::positions(array_map(fn (array $row) => [
            'key' => $row['result']['enrolment_id'],
            'gpa' => $row['result']['gpa'],
            'passed_count' => $row['result']['passed_count'],
            'total' => $row['result']['total_obtained'],
        ], $subset));

        $classPositions = $rank($rows);
        $sectionPositions = [];

        foreach (collect($rows)->groupBy(fn (array $row) => $row['result']['section_id']) as $subset) {
            $sectionPositions += $rank($subset->all());
        }

        foreach ($rows as &$row) {
            $row['result']['class_position'] = $classPositions[$row['result']['enrolment_id']];
            $row['result']['section_position'] = $sectionPositions[$row['result']['enrolment_id']];
        }
    }

    /**
     * Adds one student to the per class-and-section summary.
     *
     * @param  array<string, array<string, mixed>>  $summary
     * @param  array{enrolment: StudentEnrolment, missing: int, result: array<string, mixed>}  $row
     * @return array<string, array<string, mixed>>
     */
    private function tally(array $summary, array $row): array
    {
        $enrolment = $row['enrolment'];
        $key = $enrolment->class_id.'|'.$enrolment->section_id;

        $summary[$key] ??= [
            'class_id' => $enrolment->class_id,
            'class_name' => $enrolment->class->name,
            'section_id' => $enrolment->section_id,
            'section_name' => $enrolment->section->name,
            'students' => 0,
            'passed' => 0,
            'failed' => 0,
            'missing_marks' => 0,
        ];

        $summary[$key]['students']++;
        $summary[$key][$row['result']['is_pass'] ? 'passed' : 'failed']++;
        $summary[$key]['missing_marks'] += $row['missing'];

        return $summary;
    }
}
