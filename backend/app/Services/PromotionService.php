<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\ExamResultRepositoryInterface;
use App\Repositories\Contracts\PromotionRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Repositories\Contracts\StudentEnrolmentRepositoryInterface;
use App\Support\UniqueViolation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves a whole section from one academic year to the next in one transaction: promotes
 * students into the next class, keeps some back, marks leavers and graduates Class 12.
 * The enrolment rules (seat, group, 4th subject) are EnrolmentService's, called per row;
 * the section locks are StudentEnrolmentRepository::lockSection(); a leaver's status
 * change is StudentService::changeStatus(). See docs/tasks/student-promotion.md.
 */
class PromotionService
{
    private const PROMOTE = 'promote';

    private const RETAIN = 'retain';

    private const LEAVE = 'leave';

    private const GRADUATE = 'graduate';

    public function __construct(
        private PromotionRepositoryInterface $promotions,
        private StudentEnrolmentRepositoryInterface $enrolmentRows,
        private SectionRepositoryInterface $sections,
        private AcademicYearRepositoryInterface $years,
        private ExamResultRepositoryInterface $results,
        private EnrolmentService $enrolments,
        private StudentService $students,
    ) {}

    /**
     * One row per active student of the section with the suggested action, taken from the
     * latest published annual exam result of the source year.
     *
     * @param  array{from_academic_year_id: int, section_id: int, to_academic_year_id?: ?int, class_id?: ?int}  $filters
     * @return array{section: Section, class: Classes, next_class: ?Classes, suggested_target_section_id: ?int, needs_group_choice: bool, rows: list<array<string, mixed>>}
     */
    public function preview(array $filters): array
    {
        $fromYear = $this->years->findOrFail((int) $filters['from_academic_year_id']);
        $section = $this->sections->findOrFail((int) $filters['section_id'])->load(['class', 'shift']);
        $this->ensureSectionInClass($section, $filters['class_id'] ?? null);

        $class = $section->class;
        $isFinal = $this->isFinal($class);
        $next = $isFinal ? null : $this->promotions->findClassByNumber($class->number + 1);

        $rows = $this->promotions->activeEnrolments($section->id, $fromYear->id);
        $studentIds = $rows->pluck('student_id')->map(fn ($id) => (int) $id)->all();

        $exam = $this->results->latestPublishedAnnualExam($fromYear->id, $class->id);
        $results = $exam && $rows->isNotEmpty()
            ? $this->results->resultsForEnrolments($exam, $rows->pluck('id')->map(fn ($id) => (int) $id)->all())
            : collect();

        $enrolled = filled($filters['to_academic_year_id'] ?? null)
            ? $this->promotions->enrolledStudentIds($studentIds, (int) $filters['to_academic_year_id'])
            : null;

        return [
            'section' => $section,
            'class' => $class,
            'next_class' => $next,
            'suggested_target_section_id' => $next
                ? $this->promotions->findSectionByCodeAndShift($next->id, $section->code, $section->shift_id)?->id
                : null,
            'needs_group_choice' => in_array($class->number, [8, 10], true),
            'rows' => $rows->map(function (StudentEnrolment $enrolment) use ($results, $exam, $isFinal, $enrolled) {
                $result = $results->get($enrolment->id);

                return [
                    'enrolment' => $enrolment,
                    'exam_result' => $result ? [
                        'exam_id' => $exam->id,
                        'exam_name' => $exam->displayName(),
                        'gpa' => $result->gpa,
                        'grade' => $result->grade,
                        'is_pass' => $result->is_pass,
                    ] : null,
                    'suggested_action' => match (true) {
                        $isFinal => self::GRADUATE,
                        $result !== null && ! $result->is_pass => self::RETAIN,
                        default => self::PROMOTE,
                    },
                    'already_enrolled_in_target' => $enrolled === null
                        ? null
                        : in_array((int) $enrolment->student_id, $enrolled, true),
                ];
            })->all(),
        ];
    }

    /**
     * Applies the promotion. Every rule is checked after the sections are locked and before
     * the first write, and the whole batch is one transaction, so an error writes nothing.
     * Errors are keyed `exceptions.N.field` for a listed student, top-level otherwise.
     *
     * @param  array<string, mixed>  $data  Validated: from/to academic year, section_id, class_id?, default_target_section_id?, exceptions[].
     * @return array{summary: array<string, int>, target_sections: list<array{id: int, name: string, enrolled_after: int}>}
     */
    public function apply(array $data): array
    {
        $fromYear = $this->years->findOrFail((int) $data['from_academic_year_id']);
        $toYear = $this->years->findOrFail((int) $data['to_academic_year_id']);

        if ($toYear->year <= $fromYear->year) {
            throw ValidationException::withMessages([
                'to_academic_year_id' => ['The target academic year must be later than the source academic year.'],
            ]);
        }

        try {
            return DB::transaction(fn () => $this->applyLocked($data, $fromYear, $toYear));
        } catch (UniqueConstraintViolationException $e) {
            // The already-enrolled check runs before the writes; a concurrent request can still win.
            if (! UniqueViolation::is($e, 'student_enrolments', ['student_id', 'academic_year_id'], 'student_enrolments_student_id_academic_year_id_unique')) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'already_enrolled' => ['A student was enrolled in the target academic year in the meantime. Review the list and try again.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applyLocked(array $data, AcademicYear $fromYear, AcademicYear $toYear): array
    {
        $exceptions = array_values($data['exceptions'] ?? []);
        $defaultId = filled($data['default_target_section_id'] ?? null) ? (int) $data['default_target_section_id'] : null;

        // Lock order: the source section first, then every target in ascending id order, so
        // two concurrent promotions can never wait on each other in a cycle.
        $source = $this->enrolmentRows->lockSection((int) $data['section_id']);
        $sections = [$source->id => $source];

        $targetIds = collect($exceptions)->pluck('target_section_id')->push($defaultId)
            ->filter()->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => $id === $source->id)->sort()->values();

        foreach ($targetIds as $id) {
            $sections[$id] = $this->enrolmentRows->lockSection($id);
        }

        $this->ensureSectionInClass($source, $data['class_id'] ?? null);

        $class = $source->class;
        $isFinal = $this->isFinal($class);
        $enrolments = $this->promotions->activeEnrolments($source->id, $fromYear->id)->keyBy('student_id');
        $enrolledIds = $this->promotions->enrolledStudentIds($enrolments->keys()->map(fn ($id) => (int) $id)->all(), $toYear->id);

        $errors = [];
        $addError = function (string $key, string $message) use (&$errors) {
            $errors[$key][] = $message;
        };

        // The exceptions must name distinct students of the source section.
        $byStudent = [];
        foreach ($exceptions as $index => $exception) {
            $studentId = (int) $exception['student_id'];

            if (! $enrolments->has($studentId)) {
                $addError("exceptions.{$index}.student_id", 'This student is not an active student of the source section.');
            } elseif (isset($byStudent[$studentId])) {
                $addError("exceptions.{$index}.student_id", 'This student is listed more than once.');
            } else {
                $byStudent[$studentId] = $index;
            }
        }

        $plans = [];
        $newSeats = [];
        $alreadyEnrolled = [];
        $missingGroups = [];
        $defaultMissing = false;

        foreach ($enrolments as $studentId => $enrolment) {
            $index = $byStudent[$studentId] ?? null;
            $exception = $index !== null ? $exceptions[$index] : [];
            $name = $enrolment->student->displayName();
            $action = $exception['action'] ?? self::PROMOTE;

            // Class 12 students graduate: there is no next class to promote into.
            if ($isFinal && $action === self::PROMOTE) {
                $action = self::GRADUATE;
            }

            $plan = ['enrolment' => $enrolment, 'action' => $action, 'index' => $index, 'target' => null, 'group' => null, 'optional' => null];

            if (in_array($action, [self::LEAVE, self::GRADUATE], true)) {
                $plans[] = $plan;

                continue;
            }

            // Where a rule fails: the student's own row, or the shared field for students
            // that are not listed in the exceptions.
            $fail = function (string $field, string $message, ?string $shared = null) use ($index, $name, $addError) {
                if ($index !== null) {
                    $addError("exceptions.{$index}.{$field}", $message);
                } else {
                    $addError($shared ?? $field, "{$name}: {$message}");
                }
            };

            if (in_array((int) $studentId, $enrolledIds, true)) {
                if ($index !== null) {
                    $addError("exceptions.{$index}.student_id", 'This student is already enrolled in the target academic year.');
                } else {
                    $alreadyEnrolled[] = $name;
                }

                continue;
            }

            $targetId = $action === self::RETAIN
                ? (int) ($exception['target_section_id'] ?? $source->id)
                : (filled($exception['target_section_id'] ?? null) ? (int) $exception['target_section_id'] : $defaultId);

            if ($targetId === null) {
                $defaultMissing = true;

                continue;
            }

            $target = $sections[$targetId];
            $targetClass = $target->class;
            $expectedClass = $action === self::RETAIN ? $class->number : $class->number + 1;

            if ((int) $targetClass->number !== $expectedClass) {
                $fail('target_section_id', "The target section must belong to Class {$expectedClass}.", 'default_target_section_id');

                continue;
            }

            if ($reason = $this->enrolments->sectionClosedReason($target)) {
                $fail('target_section_id', $reason, 'default_target_section_id');

                continue;
            }

            // Group and 4th subject: carried over within a class pair that already has
            // groups (9 to 10, 11 to 12, or a retained student), taken from the section
            // or the exception when entering Class 9 or 11, and absent below Class 9.
            $entering = $action === self::PROMOTE && in_array((int) $class->number, [8, 10], true);
            $hasOptional = array_key_exists('optional_subject_id', $exception);

            if ($entering && $targetClass->hasGroups()) {
                $group = $exception['group'] ?? $target->group;
                $optional = $hasOptional ? $exception['optional_subject_id'] : null;

                if ($group === null) {
                    if ($index !== null) {
                        $addError("exceptions.{$index}.group", 'Choose a group for this student.');
                    } else {
                        $missingGroups[] = $name;
                    }

                    continue;
                }
            } else {
                $group = $exception['group'] ?? ($targetClass->hasGroups() ? $enrolment->group : null);
                $optional = $hasOptional
                    ? $exception['optional_subject_id']
                    : ($targetClass->hasGroups() ? $enrolment->optional_subject_id : null);
            }

            $problems = $this->enrolments->placementErrors($target, $group, $optional !== null ? (int) $optional : null);

            foreach ($problems as $key => $messages) {
                $fail(str_replace('enrolment.', '', $key), $messages[0]);
            }

            if ($problems !== []) {
                continue;
            }

            $plan['target'] = $target;
            $plan['group'] = $group;
            $plan['optional'] = $optional !== null ? (int) $optional : null;
            $plans[] = $plan;
            $newSeats[$target->id] = ($newSeats[$target->id] ?? 0) + 1;
        }

        if ($defaultMissing) {
            $addError('default_target_section_id', 'Choose a default target section.');
        }

        if ($missingGroups !== []) {
            $addError('missing_groups', 'Choose a group for: '.implode(', ', $missingGroups).'.');
        }

        if ($alreadyEnrolled !== []) {
            $addError('already_enrolled', 'Already enrolled in the target academic year: '.implode(', ', $alreadyEnrolled).'.');
        }

        // Capacity is checked for the whole batch per target section.
        foreach ($newSeats as $sectionId => $count) {
            $free = $sections[$sectionId]->capacity - $this->enrolmentRows->countActiveInSection($sectionId, $toYear->id);

            if ($count <= $free) {
                continue;
            }

            $message = 'The section does not have enough free seats ('.max($free, 0)." free, {$count} students).";

            if ($sectionId === $defaultId) {
                $addError('default_target_section_id', $message);
            }

            foreach ($plans as $plan) {
                if ($plan['index'] !== null && $plan['target']?->id === $sectionId && ! empty($exceptions[$plan['index']]['target_section_id'])) {
                    $addError("exceptions.{$plan['index']}.target_section_id", $message);
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $this->write($plans, $fromYear, $toYear, $newSeats, $sections);
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     * @param  array<int, int>  $newSeats
     * @param  array<int, Section>  $sections
     */
    private function write(array $plans, AcademicYear $fromYear, AcademicYear $toYear, array $newSeats, array $sections): array
    {
        $summary = ['promoted' => 0, 'retained' => 0, 'left' => 0, 'graduated' => 0];
        $today = Carbon::now('Asia/Dhaka')->toDateString();

        foreach ($plans as $plan) {
            /** @var StudentEnrolment $enrolment */
            $enrolment = $plan['enrolment'];
            /** @var Student $student */
            $student = $enrolment->student;

            switch ($plan['action']) {
                case self::PROMOTE:
                case self::RETAIN:
                    $this->enrolments->save($student, $toYear, [
                        'section_id' => $plan['target']->id,
                        'group' => $plan['group'],
                        'optional_subject_id' => $plan['optional'],
                    ]);
                    $retained = $plan['action'] === self::RETAIN;
                    $this->enrolmentRows->update($enrolment, [
                        'status' => $retained ? StudentEnrolment::STATUS_RETAINED : StudentEnrolment::STATUS_PROMOTED,
                    ]);
                    $summary[$retained ? 'retained' : 'promoted']++;
                    break;

                default:
                    $graduated = $plan['action'] === self::GRADUATE;
                    $student = $this->students->changeStatus(
                        $student,
                        $graduated ? Student::STATUS_GRADUATED : Student::STATUS_LEFT,
                        $today,
                    );
                    $this->enrolments->syncStatus($student, $fromYear);
                    $summary[$graduated ? 'graduated' : 'left']++;
            }
        }

        $targets = [];
        foreach ($newSeats as $sectionId => $count) {
            $targets[] = [
                'id' => $sectionId,
                'name' => $sections[$sectionId]->name,
                'enrolled_after' => $this->enrolmentRows->countActiveInSection($sectionId, $toYear->id),
            ];
        }

        return ['summary' => $summary, 'target_sections' => $targets];
    }

    private function isFinal(Classes $class): bool
    {
        return (int) $class->number >= Classes::MAX_NUMBER;
    }

    private function ensureSectionInClass(Section $section, mixed $classId): void
    {
        if (filled($classId) && (int) $section->class_id !== (int) $classId) {
            throw ValidationException::withMessages([
                'section_id' => ['The section does not belong to the source class.'],
            ]);
        }
    }
}
