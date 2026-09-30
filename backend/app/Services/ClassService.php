<?php

namespace App\Services;

use App\Models\Classes;
use App\Repositories\Contracts\ClassRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassService
{
    public function __construct(private ClassRepositoryInterface $classes) {}

    /**
     * @param  array{search?: string, is_active?: mixed, level?: string}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->classes->paginate($filters, $perPage);
    }

    /**
     * Route model binding resolves $class without going through the repository, so
     * its sections are loaded here for the show() response (see
     * docs/architecture-guidelines.md and StaffService::find() for the same pattern).
     */
    public function find(Classes $class): Classes
    {
        return $class->loadCount('sections')->load('sections');
    }

    public function create(array $data): Classes
    {
        return $this->withUniqueFields(fn () => $this->classes->create($data));
    }

    public function update(Classes $class, array $data): Classes
    {
        return $this->withUniqueFields(fn () => DB::transaction(function () use ($class, $data) {
            // Locked first, so a concurrent curriculum or section change for this class
            // can't slip in between the checks below and the update.
            $this->classes->lockForUpdate($class);

            // Checked against the model with the input applied: dropping below Class 9
            // would leave existing sections with a group the class can no longer have.
            $dropsGroups = array_key_exists('number', $data) && ! (clone $class)->fill($data)->hasGroups();

            if ($dropsGroups && $this->classes->hasGroupedSections($class)) {
                throw ValidationException::withMessages([
                    'number' => ['Sections of this class have a group; remove it first, since groups are only allowed for Class 9 and above.'],
                ]);
            }

            if ($dropsGroups && $this->classes->hasGroupedCurriculum($class)) {
                throw ValidationException::withMessages([
                    'number' => ['The curriculum of this class has group or optional subjects; remove them first, since they are only allowed for Class 9 and above.'],
                ]);
            }

            return $this->classes->update($class, $data);
        }));
    }

    public function delete(Classes $class): void
    {
        // Foreign keys don't protect soft-deleted rows, so check every reference here
        // (see SubjectService::delete() for the same pattern).
        abort_if($this->classes->hasSections($class), 409, 'Class has sections and cannot be deleted.');
        abort_if($this->classes->hasStudents($class), 409, 'Class has students and cannot be deleted.');
        abort_if($this->classes->hasAttendances($class), 409, 'Class has attendance records and cannot be deleted.');
        abort_if($this->classes->hasExamSchedules($class), 409, 'Class has exam schedules and cannot be deleted.');
        abort_if($this->classes->hasFeeStructures($class), 409, 'Class has fee structures and cannot be deleted.');
        abort_if($this->classes->hasSubjectAssignments($class), 409, 'Class has subject assignments and cannot be deleted.');

        // The curriculum rows cascade in the database, but a soft delete doesn't fire
        // that, so remove them explicitly in the same transaction.
        DB::transaction(function () use ($class) {
            $this->classes->deleteCurriculum($class);
            $this->classes->delete($class);
        });
    }

    /**
     * The unique rules run before the write, so a concurrent request can still hit the
     * database index (see SubjectService::withUniqueCode()). Classes has two unique
     * columns (number, code); best-effort report whichever the database's own message
     * names, defaulting to "code" when that can't be told.
     */
    private function withUniqueFields(callable $write): Classes
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            $field = str_contains($e->getMessage(), 'number') ? 'number' : 'code';

            throw ValidationException::withMessages([
                $field => ["The {$field} has already been taken."],
            ]);
        }
    }
}
