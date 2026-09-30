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
     * @param  array{section_id: int, group?: ?string, optional_subject_id?: ?int, roll_number?: ?int}  $data
     */
    public function save(Student $student, AcademicYear $year, array $data): StudentEnrolment
    {
        try {
            return DB::transaction(function () use ($student, $year, $data) {
                $section = $this->enrolments->lockSection((int) $data['section_id']);
                $existing = $this->enrolments->forStudentAndYear($student, $year->id);

                $this->ensureValid($section, $year, $data, $existing);

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
                    : $this->enrolments->create($attributes + ['student_id' => $student->id, 'academic_year_id' => $year->id]);
            });
        } catch (UniqueConstraintViolationException $e) {
            // The roll check runs before the write; a concurrent request can still win.
            if (! str_contains($e->getMessage(), 'roll')) {
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
    private function ensureValid(Section $section, AcademicYear $year, array $data, ?StudentEnrolment $existing): void
    {
        $class = $section->class;
        $group = $data['group'] ?? null;
        $optionalSubjectId = $data['optional_subject_id'] ?? null;
        $roll = $data['roll_number'] ?? null;
        $errors = [];

        // A student keeping their seat can be edited even if the section or its shift
        // was deactivated (or filled up) since; only a move into a section is checked.
        $movingIn = ! $existing || $existing->section_id !== $section->id;

        if ($movingIn) {
            if (! $section->is_active) {
                $errors['enrolment.section_id'][] = 'The section is not active.';
            } elseif (! $section->shift || ! $section->shift->is_active) {
                $errors['enrolment.section_id'][] = "The section's shift is not active.";
            } elseif ($this->enrolments->countActiveInSection($section->id, $year->id) >= $section->capacity) {
                $errors['enrolment.section_id'][] = 'The section is full.';
            }
        }

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

        if ($roll !== null && $this->enrolments->rollNumberTaken($section->id, $year->id, (int) $roll, $existing?->id)) {
            $errors['enrolment.roll_number'][] = 'This roll number is already taken in the section.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function isOptionalChoice(Classes $class, string $group, int $subjectId): bool
    {
        return $this->curriculum->forClassAndGroup($class, $group)
            ->contains(fn (ClassSubject $row) => $row->type === ClassSubject::TYPE_OPTIONAL && (int) $row->subject_id === $subjectId);
    }
}
