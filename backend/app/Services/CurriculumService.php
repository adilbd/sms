<?php

namespace App\Services;

use App\Models\Classes;
use App\Models\ClassSubject;
use App\Repositories\Contracts\ClassSubjectRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A class's curriculum: which subjects it studies, per group from Class 9, compulsory or
 * optional (a 4th-subject choice). Read and replaced as a whole through ClassController's
 * curriculum actions; it has no standalone CRUD endpoint.
 */
class CurriculumService
{
    public function __construct(private ClassSubjectRepositoryInterface $curriculum) {}

    /**
     * With a $group, the effective list for it: the class-wide rows plus that group's rows.
     */
    public function get(Classes $class, ?string $group = null): Collection
    {
        return filled($group)
            ? $this->curriculum->forClassAndGroup($class, $group)
            : $this->curriculum->forClass($class);
    }

    /**
     * Replaces the whole curriculum in one transaction; array order becomes sort_order.
     * Every rule is checked before anything is written, and errors are keyed per row
     * (for example `subjects.3.group`).
     *
     * @param  list<array{subject_id: int, group?: ?string, type: string}>  $subjects
     */
    public function sync(Classes $class, array $subjects): Collection
    {
        $rows = array_map(fn (array $row) => [
            'subject_id' => (int) $row['subject_id'],
            'group' => $row['group'] ?? null,
            'type' => $row['type'],
        ], array_values($subjects));

        // The class row is locked first, so two concurrent replacements for it queue up
        // and each validates against (and writes over) the other's committed result.
        DB::transaction(function () use ($class, $rows) {
            $this->curriculum->lockClass($class);
            $this->ensureValid($class, $rows);
            $this->curriculum->sync($class, $rows);
        });

        return $this->curriculum->forClass($class);
    }

    /**
     * @param  list<array{subject_id: int, group: ?string, type: string}>  $rows
     */
    private function ensureValid(Classes $class, array $rows): void
    {
        $errors = [];
        $seen = [];
        $common = [];

        foreach ($rows as $i => $row) {
            if (! $class->hasGroups()) {
                if ($row['group'] !== null) {
                    $errors["subjects.{$i}.group"][] = 'A group is only allowed for Class 9 and above.';
                }

                if ($row['type'] !== ClassSubject::TYPE_COMPULSORY) {
                    $errors["subjects.{$i}.type"][] = 'Optional (4th) subjects are only allowed for Class 9 and above.';
                }
            }

            $key = $row['subject_id'].'|'.($row['group'] ?? '');

            if (isset($seen[$key])) {
                $errors["subjects.{$i}.subject_id"][] = 'This subject is already listed for the same group.';
            }

            $seen[$key] = true;

            if ($row['group'] === null) {
                $common[$row['subject_id']] = true;
            }
        }

        // A subject common to the class can't also be listed for a specific group.
        foreach ($rows as $i => $row) {
            if ($row['group'] !== null && isset($common[$row['subject_id']])) {
                $errors["subjects.{$i}.group"][] = 'This subject is already common to the whole class, so it cannot also be listed for a group.';
            }
        }

        foreach ($this->curriculum->unusableRowIndexes($class, $rows) as $i) {
            $errors["subjects.{$i}.subject_id"][] = 'Only active subjects can be added to a curriculum.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
