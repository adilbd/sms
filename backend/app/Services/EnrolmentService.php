<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Repositories\Contracts\ClassSubjectRepositoryInterface;
use App\Repositories\Contracts\StudentEnrolmentRepositoryInterface;
use App\Support\AcademicGroup;
use App\Support\EnrolmentStart;
use App\Support\UniqueViolation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A student's place in one academic year: section (the class comes from it), group from
 * Class 9, 4th subject and roll number. Called by StudentService inside its transaction;
 * it has no endpoint of its own. Errors are keyed `enrolment.{field}`.
 */
class EnrolmentService
{
    public function __construct(
        private StudentEnrolmentRepositoryInterface $enrolments,
        private ClassSubjectRepositoryInterface $curriculum,
    ) {}

    /**
     * Creates the student's enrolment for $year, or updates it when one exists. The
     * section row is locked first, so two requests filling the last seat queue up and
     * the second one sees it taken.
     *
     * @param  array{section_id: int, group?: ?string, optional_subject_id?: ?int, roll_number?: ?int, enrolled_on?: ?string}  $data  `enrolled_on` only applies to a new enrolment (default: the student's admission date clamped to the year, see EnrolmentStart); promotion passes the target year's start date.
     */
    public function save(Student $student, AcademicYear $year, array $data): StudentEnrolment
    {
        try {
            return DB::transaction(function () use ($student, $year, $data) {
                $section = $this->enrolments->lockSection((int) $data['section_id']);
                $existing = $this->enrolments->forStudentAndYear($student, $year->id);

                $this->ensureValid($section, $year, $data, $existing, $this->statusFor($student));

                $attributes = [
                    'class_id' => $section->class_id,
                    'section_id' => $section->id,
                    'group' => $data['group'] ?? null,
                    'optional_subject_id' => $data['optional_subject_id'] ?? null,
                    'roll_number' => $data['roll_number'] ?? null,
                    'status' => $this->statusFor($student),
                ];

                return $existing
                    ? $this->enrolments->update($existing, $attributes)
                    : $this->enrolments->create($attributes + [
                        'student_id' => $student->id,
                        'academic_year_id' => $year->id,
                        'enrolled_on' => $data['enrolled_on'] ?? EnrolmentStart::for($year->start_date, $year->end_date, $student->admission_date),
                    ]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The roll check runs before the write; a concurrent request can still win.
            if (! UniqueViolation::is($e, 'student_enrolments', ['section_id', 'academic_year_id', 'roll_number'], 'student_enrolments_section_year_roll_unique')) {
                throw $e;
            }

            throw ValidationException::withMessages([
                'enrolment.roll_number' => ['This roll number is already taken in the section.'],
            ]);
        }
    }

    /**
     * Keeps the enrolment's status in step with the student's (a student who left or
     * graduated stops taking a seat). Enrolments already promoted or retained by a
     * promotion are history and stay as they are.
     */
    public function syncStatus(Student $student, AcademicYear $year): void
    {
        $existing = $this->enrolments->forStudentAndYear($student, $year->id);
        $status = $this->statusFor($student);

        if ($existing && $existing->status !== $status
            && in_array($existing->status, [StudentEnrolment::STATUS_ACTIVE, StudentEnrolment::STATUS_LEFT, StudentEnrolment::STATUS_GRADUATED], true)) {
            $this->enrolments->update($existing, ['status' => $status]);
        }
    }

    /**
     * Frees the student's seat and roll number for $year (used when the student is
     * deleted). The row stays as history, marked as left; its roll number is cleared
     * because the unique index on (section, year, roll) would otherwise keep it taken.
     */
    public function release(Student $student, AcademicYear $year): void
    {
        $existing = $this->enrolments->forStudentAndYear($student, $year->id);

        if ($existing && $existing->status === StudentEnrolment::STATUS_ACTIVE) {
            $this->enrolments->update($existing, ['status' => StudentEnrolment::STATUS_LEFT, 'roll_number' => null]);
        }
    }

    public function history(Student $student): Collection
    {
        return $this->enrolments->historyFor($student);
    }

    private function statusFor(Student $student): string
    {
        return match ($student->status) {
            Student::STATUS_LEFT => StudentEnrolment::STATUS_LEFT,
            Student::STATUS_GRADUATED => StudentEnrolment::STATUS_GRADUATED,
            default => StudentEnrolment::STATUS_ACTIVE,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ensureValid(Section $section, AcademicYear $year, array $data, ?StudentEnrolment $existing, string $newStatus): void
    {
        $roll = $data['roll_number'] ?? null;
        $errors = [];

        // The section checks apply whenever the enrolment starts taking a seat: a new
        // active enrolment, a move into another section, or one turning active again.
        // A student keeping their seat can be edited even if the section or its shift
        // was deactivated (or filled up) since, and a student who is not active (left,
        // graduated) takes no seat, so is never blocked by these.
        $takesSeat = $newStatus === StudentEnrolment::STATUS_ACTIVE
            && (! $existing
                || $existing->section_id !== $section->id
                || $existing->status !== StudentEnrolment::STATUS_ACTIVE);

        if ($takesSeat) {
            if ($reason = $this->sectionClosedReason($section)) {
                $errors['enrolment.section_id'][] = $reason;
            } elseif ($this->enrolments->countActiveInSection($section->id, $year->id) >= $section->capacity) {
                $errors['enrolment.section_id'][] = 'The section is full.';
            }
        }

        $errors = array_merge($errors, $this->placementErrors($section, $data['group'] ?? null, $data['optional_subject_id'] ?? null));

        if ($roll !== null && $this->enrolments->rollNumberTaken($section->id, $year->id, (int) $roll, $existing?->id)) {
            $errors['enrolment.roll_number'][] = 'This roll number is already taken in the section.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Why a section cannot take new students (it, or its shift, is inactive), or null.
     * $section must have its `shift` loaded (lockSection() does). Capacity is separate.
     */
    public function sectionClosedReason(Section $section): ?string
    {
        if (! $section->is_active) {
            return 'The section is not active.';
        }

        if (! $section->shift || ! $section->shift->is_active) {
            return "The section's shift is not active.";
        }

        return null;
    }

    /**
     * The group and 4th-subject rules for placing a student in $section (which must have
     * its `class` loaded): a group is required from Class 9, forbidden below it and must
     * match the section's group; the 4th subject is forbidden below Class 9 and must be an
     * optional curriculum row or a member of a choice pair for the class and group. Returns errors keyed
     * `enrolment.{field}`, empty when valid. Shared with PromotionService so a promotion
     * applies exactly the rules of a normal enrolment.
     *
     * @return array<string, list<string>>
     */
    public function placementErrors(Section $section, ?string $group, ?int $optionalSubjectId): array
    {
        $class = $section->class;
        $errors = [];

        if ($class->hasGroups()) {
            if ($group === null) {
                $errors['enrolment.group'][] = 'A group is required from Class 9.';
            } elseif (! in_array($group, AcademicGroup::VALUES, true)) {
                $errors['enrolment.group'][] = 'The selected group is invalid.';
            } elseif ($section->group !== null && $section->group !== $group) {
                $errors['enrolment.group'][] = 'The group must match the section\'s group.';
            }
        } else {
            if ($group !== null) {
                $errors['enrolment.group'][] = 'A group is only allowed from Class 9.';
            }

            if ($optionalSubjectId !== null) {
                $errors['enrolment.optional_subject_id'][] = 'A 4th subject is only allowed from Class 9.';
            }
        }

        if ($optionalSubjectId !== null && $class->hasGroups() && ! isset($errors['enrolment.group'])
            && ! $this->isOptionalChoice($class, (string) $group, (int) $optionalSubjectId)) {
            $errors['enrolment.optional_subject_id'][] = 'This is not an optional (4th) subject for the class and group.';
        }

        return $errors;
    }

    private function isOptionalChoice(Classes $class, string $group, int $subjectId): bool
    {
        // Either member of a choice pair qualifies (Biology or Higher Mathematics: choosing
        // one makes the other compulsory), as does any plain optional row.
        return $this->curriculum->forClassAndGroup($class, $group)
            ->contains(fn (ClassSubject $row) => ($row->type === ClassSubject::TYPE_OPTIONAL || $row->choice_group !== null)
                && (int) $row->subject_id === $subjectId);
    }
}
