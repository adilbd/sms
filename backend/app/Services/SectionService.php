<?php

namespace App\Services;

use App\Models\Classes;
use App\Models\Section;
use App\Repositories\Contracts\ClassRepositoryInterface;
use App\Repositories\Contracts\ClassTeacherRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SectionService
{
    public function __construct(
        private SectionRepositoryInterface $sections,
        private ClassRepositoryInterface $classes,
        private ClassTeacherRepositoryInterface $classTeachers,
    ) {}

    /**
     * @param  array{search?: string, class_id?: mixed, shift_id?: mixed, group?: string, is_active?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->sections->paginate($filters, $perPage);
    }

    /**
     * Route model binding resolves $section without going through the repository, so
     * its class/shift are loaded here for the show() response (see
     * docs/architecture-guidelines.md and StaffService::find() for the same pattern).
     */
    public function find(Section $section): Section
    {
        return $section->load(['class', 'shift']);
    }

    public function create(array $data): Section
    {
        $class = $this->classes->findOrFail((int) $data['class_id']);

        // A new model carries the column defaults, so an omitted group is still
        // checked (see SubjectService::create() for the same pattern).
        $this->ensureGroupAllowed($class, new Section($data));

        $section = $this->withUniqueCode(fn () => $this->sections->create($data));

        return $section->load(['class', 'shift']);
    }

    public function update(Section $section, array $data): Section
    {
        // class_id can't change (see UpdateSectionRequest), so the class is always the
        // section's own. Checked against the model with the input applied, so a
        // partial update that only sends "group" is still validated (see
        // SubjectService::update() for the same pattern).
        $class = $this->classes->findOrFail($section->class_id);
        $this->ensureGroupAllowed($class, (clone $section)->fill($data));

        // Existing class teachers must belong to the section's new shift.
        if (isset($data['shift_id'])
            && (int) $data['shift_id'] !== (int) $section->shift_id
            && $this->classTeachers->hasTeacherOutsideShift($section, (int) $data['shift_id'])) {
            throw ValidationException::withMessages([
                'shift_id' => ["A class teacher of this section doesn't belong to the new shift. Reassign or unassign them first."],
            ]);
        }

        $section = $this->withUniqueCode(fn () => $this->sections->update($section, $data));

        return $section->load(['class', 'shift']);
    }

    public function delete(Section $section): void
    {
        // Foreign keys don't protect soft-deleted rows, so check every reference here
        // (see SubjectService::delete() for the same pattern).
        abort_if($this->sections->hasStudents($section), 409, 'Section has students and cannot be deleted.');
        abort_if($this->sections->hasAttendances($section), 409, 'Section has attendance records and cannot be deleted.');
        abort_if($this->sections->hasSubjectAssignments($section), 409, 'Section has subject assignments and cannot be deleted.');

        abort_if($this->sections->hasRoutineSlots($section), 409, 'Section has a class routine and cannot be deleted.');
        abort_if($this->sections->hasHomework($section), 409, 'Section has homework and cannot be deleted.');

        DB::transaction(function () use ($section) {
            // Class-teacher rows don't block the delete; they're removed along with it.
            $this->classTeachers->deleteForSection($section);
            $this->sections->delete($section);
        });
    }

    private function ensureGroupAllowed(Classes $class, Section $section): void
    {
        if ($section->group !== null && ! $class->hasGroups()) {
            throw ValidationException::withMessages([
                'group' => ['A group is only allowed for Class 9 and above.'],
            ]);
        }
    }

    /**
     * The unique rule runs before the write, so a concurrent request can still hit the
     * database index (see SubjectService::withUniqueCode()).
     */
    private function withUniqueCode(callable $write): Section
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'code' => ['The code has already been taken for this class and shift.'],
            ]);
        }
    }
}
