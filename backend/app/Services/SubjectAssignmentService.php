<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Subject;
use App\Models\SubjectAssignment;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Repositories\Contracts\SubjectAssignmentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\UniqueViolation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Who teaches each subject in each section, per academic year: one teacher per
 * (section, subject, year). Marks may only be entered by that teacher or an admin
 * (see canEnterMarks()).
 */
class SubjectAssignmentService
{
    public function __construct(
        private SubjectAssignmentRepositoryInterface $assignments,
        private SectionRepositoryInterface $sections,
        private StaffRepositoryInterface $staff,
        private AcademicYearRepositoryInterface $years,
        private UserRepositoryInterface $users,
    ) {}

    /**
     * Defaults to the active academic year when no year is given (and to every year
     * when none is active).
     *
     * @param  array{academic_year_id?: mixed, class_id?: mixed, section_id?: mixed, staff_id?: mixed, subject_id?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        if (! filled($filters['academic_year_id'] ?? null)) {
            $active = $this->years->findActive();

            if ($active) {
                $filters['academic_year_id'] = $active->id;
            }
        }

        return $this->assignments->paginate($filters, $perPage);
    }

    public function find(SubjectAssignment $assignment): SubjectAssignment
    {
        return $assignment->load(['staff', 'subject', 'section.class']);
    }

    /**
     * @param  array{section_id: int, subject_id: int, staff_id: int, academic_year_id?: int|null}  $data
     */
    public function create(array $data): SubjectAssignment
    {
        return DB::transaction(function () use ($data) {
            $section = $this->lockClassThenSection($this->sections->findOrFail((int) $data['section_id']));
            $year = $this->resolveYear($data['academic_year_id'] ?? null);
            $subjectId = (int) $data['subject_id'];
            $staff = $this->staff->findOrFail((int) $data['staff_id']);

            if ($problem = $this->staffProblem($staff, $section)) {
                throw ValidationException::withMessages(['staff_id' => [$problem]]);
            }

            if (! in_array($subjectId, $this->assignments->curriculumSubjectIds($section->class_id, $section->group), true)) {
                throw ValidationException::withMessages(['subject_id' => [$this->notInCurriculum($section)]]);
            }

            if ($this->assignments->findFor($section->id, $subjectId, $year->id)) {
                throw $this->duplicate();
            }

            $assignment = $this->withUniqueAssignment(fn () => $this->assignments->create([
                'staff_id' => $staff->id,
                'subject_id' => $subjectId,
                'class_id' => $section->class_id,
                'section_id' => $section->id,
                'academic_year_id' => $year->id,
            ]));

            return $this->find($assignment);
        });
    }

    /**
     * Only the teacher can change: the section, subject and year are what identify the
     * assignment (the request refuses them).
     *
     * @param  array{staff_id: int}  $data
     */
    public function update(SubjectAssignment $assignment, array $data): SubjectAssignment
    {
        return DB::transaction(function () use ($assignment, $data) {
            $section = $this->lockClassThenSection($assignment->section);
            $staff = $this->staff->findOrFail((int) $data['staff_id']);

            if ($problem = $this->staffProblem($staff, $section)) {
                throw ValidationException::withMessages(['staff_id' => [$problem]]);
            }

            return $this->find($this->assignments->update($assignment, ['staff_id' => $staff->id]));
        });
    }

    public function delete(SubjectAssignment $assignment): void
    {
        $this->assignments->delete($assignment);
    }

    /**
     * Replaces the section's assignments for the year in one transaction, with the
     * section row locked. A null `staff_id` removes that subject's assignment, and a
     * subject left out of the list is removed too. Every rule is checked before anything
     * is written, with errors keyed per row (`assignments.2.staff_id`).
     *
     * @param  array{academic_year_id: int, assignments: list<array{subject_id: int, staff_id?: int|null}>}  $data
     */
    public function syncForSection(Section $section, array $data): Collection
    {
        return DB::transaction(function () use ($section, $data) {
            $locked = $this->lockClassThenSection($section);
            $year = $this->years->findOrFail((int) $data['academic_year_id']);
            $curriculum = $this->assignments->curriculumSubjectIds($locked->class_id, $locked->group);

            $errors = [];
            $wanted = [];
            $seen = [];
            $staffById = [];

            foreach (array_values($data['assignments']) as $i => $row) {
                $subjectId = (int) $row['subject_id'];
                $staffId = $row['staff_id'] ?? null;

                if (isset($seen[$subjectId])) {
                    $errors["assignments.{$i}.subject_id"][] = 'This subject is listed more than once.';
                }

                $seen[$subjectId] = true;

                // Removing an assignment is always allowed, even for a subject that has
                // left the curriculum since.
                if ($staffId === null) {
                    continue;
                }

                if (! in_array($subjectId, $curriculum, true)) {
                    $errors["assignments.{$i}.subject_id"][] = $this->notInCurriculum($locked);
                }

                $staff = $staffById[$staffId] ??= $this->staff->findOrFail((int) $staffId);

                if ($problem = $this->staffProblem($staff, $locked)) {
                    $errors["assignments.{$i}.staff_id"][] = $problem;
                }

                $wanted[$subjectId] = (int) $staffId;
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $this->withUniqueAssignment(fn () => $this->assignments->replaceForSection($locked, $year->id, $wanted));

            return $this->assignments->forSectionAndYear($locked, $year->id);
        });
    }

    /**
     * Whether $user may enter marks for $subject in $section for $year: an admin always,
     * otherwise only the user whose linked staff row (`staff.user_id`) holds that
     * assignment, and only while that staff member is still active (a retired or
     * transferred teacher may not). A user with no staff link, or another teacher, may not.
     */
    public function canEnterMarks(User $user, Section $section, Subject $subject, AcademicYear $year): bool
    {
        if ($this->users->hasRole($user, 'admin')) {
            return true;
        }

        return $this->assignments->userHoldsAssignment($user->id, $section->id, $subject->id, $year->id);
    }

    /**
     * Every assignment write takes the class row first and the section row second, always
     * in that order, so it queues behind a concurrent curriculum replacement (which locks
     * the class) and two writers can't deadlock on the pair.
     */
    private function lockClassThenSection(Section $section): Section
    {
        $this->assignments->lockClass((int) $section->class_id);

        return $this->assignments->lockSection($section);
    }

    private function notInCurriculum(Section $section): string
    {
        return $section->group === null
            ? "The subject is not in this section's class curriculum."
            : "The subject is not in the curriculum of this section's class for its group.";
    }

    private function resolveYear(mixed $academicYearId): AcademicYear
    {
        if (filled($academicYearId)) {
            return $this->years->findOrFail((int) $academicYearId);
        }

        return $this->years->findActive() ?? throw ValidationException::withMessages([
            'academic_year_id' => ['There is no active academic year. Choose a year.'],
        ]);
    }

    /**
     * Why $staff can't teach in $section, or null when they can.
     */
    private function staffProblem(Staff $staff, Section $section): ?string
    {
        if ($staff->status !== Staff::STATUS_ACTIVE) {
            return 'The staff member must be active to teach a subject.';
        }

        if ($staff->category !== Staff::CATEGORY_TEACHER) {
            return 'The staff member must be a teacher to teach a subject.';
        }

        if (! $this->staff->belongsToShift($staff, $section->shift_id)) {
            return "The staff member must belong to the section's shift.";
        }

        return null;
    }

    private function duplicate(): ValidationException
    {
        return ValidationException::withMessages([
            'subject_id' => ['This subject already has a teacher in this section for the academic year. Change that assignment instead.'],
        ]);
    }

    /**
     * The check above runs before the write, so a concurrent request can still hit the
     * unique index (see SubjectService::withUniqueCode()).
     */
    private function withUniqueAssignment(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            if (UniqueViolation::is($e, 'subject_assignments', ['section_id', 'subject_id', 'academic_year_id'], 'subject_assignments_section_subject_year_unique')) {
                throw $this->duplicate();
            }

            throw $e;
        }
    }
}
