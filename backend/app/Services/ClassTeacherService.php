<?php

namespace App\Services;

use App\Models\ClassSection;
use App\Models\Section;
use App\Models\Staff;
use App\Repositories\Contracts\ClassTeacherRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A section's class teachers (staff) per academic year: one main teacher plus any number
 * of co-teachers, and a teacher may lead several sections. Managed through
 * SectionController's class-teacher actions, not a standalone CRUD endpoint.
 */
class ClassTeacherService
{
    public function __construct(
        private ClassTeacherRepositoryInterface $classTeachers,
        private StaffRepositoryInterface $staff,
    ) {}

    /**
     * Every class-teacher row of the section, most recent year first, the main teacher first.
     */
    public function listForSection(Section $section): Collection
    {
        return $this->classTeachers->forSection($section);
    }

    /**
     * Replaces the section's class teachers for the year with exactly $data['teachers']:
     * one main teacher and any number of co-teachers (an empty list removes them all).
     * Every rule is checked before anything is written, with errors keyed per row
     * (`teachers.1.staff_id`), under a lock on the section row.
     *
     * @param  array{academic_year_id: int, teachers: list<array{staff_id: int, is_main: bool}>}  $data
     * @return Collection<int, ClassSection>
     */
    public function replace(Section $section, array $data): Collection
    {
        $academicYearId = (int) $data['academic_year_id'];
        $teachers = array_values($data['teachers']);

        return DB::transaction(function () use ($section, $academicYearId, $teachers) {
            $locked = $this->classTeachers->lockSection($section);
            $errors = [];
            $mains = [];

            foreach ($teachers as $i => $row) {
                if (! empty($row['is_main'])) {
                    $mains[] = $i;
                }

                $staff = $this->staff->findOrFail((int) $row['staff_id']);

                if ($problem = $this->leadProblem($staff, $locked)) {
                    $errors["teachers.{$i}.staff_id"][] = $problem;
                }
            }

            if ($teachers !== [] && $mains === []) {
                $errors['teachers'][] = 'Choose one main class teacher.';
            }

            foreach (array_slice($mains, 1) as $i) {
                $errors["teachers.{$i}.is_main"][] = 'Only one class teacher can be the main teacher.';
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $this->withUniqueAssignment(fn () => $this->classTeachers->replaceForSectionAndYear(
                $locked,
                $academicYearId,
                array_map(fn (array $row) => ['staff_id' => (int) $row['staff_id'], 'is_main' => ! empty($row['is_main'])], $teachers),
            ));

            return $this->classTeachers->forSectionAndYear($locked, $academicYearId);
        });
    }

    /**
     * Why $staff can't lead $section, or null when they can.
     */
    private function leadProblem(Staff $staff, Section $section): ?string
    {
        if ($staff->status !== Staff::STATUS_ACTIVE) {
            return 'The staff member must be active to lead a section.';
        }

        if ($staff->category !== Staff::CATEGORY_TEACHER) {
            return 'The staff member must be a teacher to lead a section.';
        }

        if (! $this->staff->belongsToShift($staff, $section->shift_id)) {
            return "The staff member must belong to the section's shift.";
        }

        return null;
    }

    /**
     * The checks above run before the write, so a concurrent request can still hit the
     * database index (see SubjectService::withUniqueCode()).
     */
    private function withUniqueAssignment(callable $write): void
    {
        try {
            $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'teachers' => ['The class teachers were just changed by someone else. Reload and try again.'],
            ]);
        }
    }
}
