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
     * (for example `subjects.3.group`, `subjects.3.mcq_pass`).
     *
     * A row may carry the marks scheme (`written_full` ... `practical_pass`, `paper_group`).
     * Only the keys a row sends are passed on: a row that sends no part field keeps its
     * saved marks (a new row gets its subject's total/pass as written marks), and a row
     * that omits `paper_group` keeps its saved pairing.
     *
     * @param  list<array{subject_id: int, group?: ?string, type: string}>  $subjects
     */
    public function sync(Classes $class, array $subjects): Collection
    {
        $rows = array_map(function (array $row) {
            $normalized = [
                'subject_id' => (int) $row['subject_id'],
                'group' => $row['group'] ?? null,
                'type' => $row['type'],
            ];

            foreach ([...ClassSubject::MARK_FIELDS, 'paper_group'] as $field) {
                if (array_key_exists($field, $row)) {
                    $normalized[$field] = $row[$field] === null || $row[$field] === ''
                        ? null
                        : ($field === 'paper_group' ? (string) $row[$field] : (int) $row[$field]);
                }
            }

            return $normalized;
        }, array_values($subjects));

        // The class row is locked first, so two concurrent replacements for it queue up
        // and each validates against (and writes over) the other's committed result.
        DB::transaction(function () use ($class, $rows) {
            $locked = $this->curriculum->lockClass($class);
            $this->ensureValid($locked, $rows);
            $this->curriculum->sync($locked, $rows);
        });

        return $this->curriculum->forClass($class);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
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

        foreach ($rows as $i => $row) {
            foreach ($this->partErrors($row) as $field => $message) {
                $errors["subjects.{$i}.{$field}"][] = $message;
            }
        }

        foreach ($this->paperGroupErrors($class, $rows) as $i => $message) {
            $errors["subjects.{$i}.paper_group"][] = $message;
        }

        $assigned = $this->curriculum->assignedSubjects($class);
        $listed = array_column($rows, 'subject_id');

        foreach ($assigned as $subjectId => $name) {
            if (! in_array($subjectId, $listed, true)) {
                $errors['subjects'][] = "{$name} still has subject-teacher assignments in this class. Unassign it first, then remove it from the curriculum.";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The part rules for a row that sends any part field: at least one part, each part's
     * full and pass set together, pass <= full, full >= 1. Keyed by the field to blame.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function partErrors(array $row): array
    {
        if (array_intersect(ClassSubject::MARK_FIELDS, array_keys($row)) === []) {
            return [];
        }

        $errors = [];
        $anySet = false;

        foreach (ClassSubject::PARTS as $part) {
            $full = $row["{$part}_full"] ?? null;
            $pass = $row["{$part}_pass"] ?? null;

            if ($full === null && $pass === null) {
                continue;
            }

            $anySet = true;

            if ($full === null) {
                $errors["{$part}_full"] = "The {$part} full marks are required when the pass marks are set.";
            } elseif ($pass === null) {
                $errors["{$part}_pass"] = "The {$part} pass marks are required when the full marks are set.";
            } elseif ($full < 1) {
                $errors["{$part}_full"] = "The {$part} full marks must be at least 1.";
            } elseif ($pass > $full) {
                $errors["{$part}_pass"] = "The {$part} pass marks cannot be more than the full marks.";
            }
        }

        if (! $anySet) {
            $errors['written_full'] = 'At least one marks part (written, MCQ or practical) must be set.';
        }

        return $errors;
    }

    /**
     * A paper group pairs at most 2 rows of the class, and both must share type and group.
     * A row that omits `paper_group` keeps its saved one, so the saved pairing is read
     * only when some row needs it. Keyed by row index.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, string>
     */
    private function paperGroupErrors(Classes $class, array $rows): array
    {
        $saved = null;
        $members = [];

        foreach ($rows as $i => $row) {
            if (array_key_exists('paper_group', $row)) {
                $paperGroup = $row['paper_group'];
            } else {
                $saved ??= $this->curriculum->savedPaperGroups($class);
                $paperGroup = $saved[$row['subject_id'].'|'.($row['group'] ?? '')] ?? null;
            }

            if ($paperGroup !== null) {
                $members[$paperGroup][] = $i;
            }
        }

        $errors = [];

        foreach ($members as $paperGroup => $indexes) {
            $first = $rows[$indexes[0]];

            foreach (array_slice($indexes, 1) as $position => $i) {
                if ($position >= 1) {
                    $errors[$i] = "The paper group \"{$paperGroup}\" can have at most 2 subjects.";
                } elseif ($rows[$i]['type'] !== $first['type']) {
                    $errors[$i] = "Both subjects in the paper group \"{$paperGroup}\" must be compulsory, or both optional.";
                } elseif (($rows[$i]['group'] ?? null) !== ($first['group'] ?? null)) {
                    $errors[$i] = "Both subjects in the paper group \"{$paperGroup}\" must be in the same group.";
                }
            }
        }

        return $errors;
    }
}
