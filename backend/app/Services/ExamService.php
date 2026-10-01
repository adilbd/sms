<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\ClassSubjectRepositoryInterface;
use App\Repositories\Contracts\ExamRepositoryInterface;
use App\Support\UniqueViolation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Exams of an academic year and the classes they are held for. Each class's subject
 * schedule (exam_subjects) is generated from the class's curriculum as a snapshot, so later
 * curriculum edits don't change a past exam. Marks are entered through ExamMarkService; the
 * subject schedule is edited through ExamScheduleService.
 *
 * Lock order (see ExamRepositoryInterface::lockExam()): the classes whose curriculum is
 * read, ascending, then the exam row.
 */
class ExamService
{
    public function __construct(
        private ExamRepositoryInterface $exams,
        private AcademicYearRepositoryInterface $years,
        private ClassSubjectRepositoryInterface $curriculum,
    ) {}

    /**
     * Defaults to the active academic year when no year is given (and to every year when
     * none is active).
     *
     * @param  array{academic_year_id?: mixed, type?: mixed, status?: mixed, search?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        if (! filled($filters['academic_year_id'] ?? null)) {
            $active = $this->years->findActive();

            if ($active) {
                $filters['academic_year_id'] = $active->id;
            }
        }

        return $this->exams->paginate($filters, $perPage);
    }

    public function find(Exam $exam): Exam
    {
        return $this->exams->loadDetail($exam);
    }

    /**
     * Creates the exam and generates every selected class's schedule in one transaction.
     *
     * @param  array{academic_year_id: int, name_en?: ?string, name_bn?: ?string, code: string, type: string, start_date: string, end_date: string, class_ids: list<int>}  $data
     */
    public function create(array $data): Exam
    {
        $classIds = array_values(array_unique(array_map('intval', $data['class_ids'])));
        unset($data['class_ids']);

        $year = $this->years->findOrFail((int) $data['academic_year_id']);
        $draft = new Exam($data);

        $this->ensureHasName($draft);
        $this->ensureDatesWithinYear($draft, $year);

        $exam = DB::transaction(function () use ($data, $classIds) {
            $classes = $this->lockClasses($classIds);
            $snapshots = $this->snapshotsFor($classes, $classIds);

            $exam = $this->withUniqueCode(fn () => $this->exams->create($data));

            foreach ($classes as $class) {
                $this->exams->replaceClassSubjects($exam, $class, $snapshots[$class->id]);
            }

            return $exam;
        });

        return $this->find($exam);
    }

    /**
     * Updates the exam. A `class_ids` list adds the classes that are new (their schedule is
     * generated) and removes the ones left out (409 once marks exist for the class). The
     * academic year never changes (the request refuses it).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Exam $exam, array $data): Exam
    {
        $classIds = array_key_exists('class_ids', $data)
            ? array_values(array_unique(array_map('intval', $data['class_ids'])))
            : null;
        unset($data['class_ids']);

        $exam = DB::transaction(function () use ($exam, $data, $classIds) {
            // Classes to add are locked before the exam row (the lock order).
            $current = $classIds === null ? [] : $this->exams->classIds($exam);
            $toAdd = $classIds === null ? [] : array_values(array_diff($classIds, $current));
            $classes = $this->lockClasses($toAdd);
            $snapshots = $this->snapshotsFor($classes, $toAdd, $classIds ?? []);

            $locked = $this->exams->lockExam($exam);

            // The classes of a processed or published exam are the ones its results cover.
            if ($classIds !== null && in_array($locked->status, [Exam::STATUS_PROCESSED, Exam::STATUS_PUBLISHED], true)) {
                $held = $this->exams->classIds($locked);

                abort_if(
                    array_diff($classIds, $held) !== [] || array_diff($held, $classIds) !== [],
                    409,
                    'The classes of an exam with results cannot be changed. Unpublish and reprocess it first.'
                );
            }

            if ($data !== []) {
                $applied = (clone $locked)->fill($data);
                $this->ensureHasName($applied);
                $this->ensureDatesWithinYear($applied, $this->years->findOrFail((int) $locked->academic_year_id));
            }

            if ($classIds !== null) {
                // Re-read under the lock: another request may have changed the classes.
                $current = $this->exams->classIds($locked);

                foreach (array_diff($current, $classIds) as $removedId) {
                    abort_if(
                        $this->exams->hasMarksForClass($locked, $removedId),
                        409,
                        'Marks have already been entered for a class you are removing. It cannot be removed from the exam.'
                    );
                }

                foreach (array_diff($current, $classIds) as $removedId) {
                    $this->exams->deleteClassSubjects($locked, $removedId);
                }

                // Additions come from this locked read, not the earlier one, so a class
                // that was removed concurrently is added back rather than silently dropped.
                $additions = array_values(array_diff($classIds, $current));

                // A class that only became missing after the first read was never locked
                // (the class locks come before the exam lock), so it has no snapshot.
                abort_if(
                    array_diff($additions, array_keys($snapshots)) !== [],
                    409,
                    "The exam's classes changed while you were saving. Reload the exam and try again."
                );

                foreach ($classes as $class) {
                    if (in_array($class->id, $additions, true)) {
                        $this->exams->replaceClassSubjects($locked, $class, $snapshots[$class->id]);
                    }
                }
            }

            return $data === [] ? $locked : $this->withUniqueCode(fn () => $this->exams->update($locked, $data));
        });

        return $this->find($exam);
    }

    public function delete(Exam $exam): void
    {
        DB::transaction(function () use ($exam) {
            $locked = $this->exams->lockExam($exam);

            // A processed or published exam has results, which a soft delete wouldn't cascade to.
            abort_if(
                in_array($locked->status, [Exam::STATUS_PROCESSED, Exam::STATUS_PUBLISHED], true) || $this->exams->hasResults($locked),
                409,
                'This exam has results and cannot be deleted.'
            );

            // Marks (even one) lock the exam: a soft delete wouldn't cascade to them.
            abort_if($this->exams->hasMarks($locked), 409, 'Marks have been entered for this exam and it cannot be deleted.');

            // Without its subjects the exam no longer pins its classes and subjects.
            $this->exams->deleteSubjects($locked);
            $this->exams->delete($locked);
        });
    }

    /**
     * draft → marks_entry. Any other status is a 409.
     */
    public function openMarksEntry(Exam $exam): Exam
    {
        $exam = DB::transaction(function () use ($exam) {
            $locked = $this->exams->lockExam($exam);

            abort_unless($locked->status === Exam::STATUS_DRAFT, 409, 'Mark entry can only be opened for an exam that is still a draft.');

            return $this->exams->update($locked, ['status' => Exam::STATUS_MARKS_ENTRY]);
        });

        return $this->find($exam);
    }

    /**
     * Re-syncs one class's schedule from its curriculum. A subject that is still in the
     * curriculum keeps its date and times; 409 once any marks exist for the class, and
     * 404 when the exam isn't held for the class.
     */
    public function regenerateClass(Exam $exam, Classes $class): Exam
    {
        $exam = DB::transaction(function () use ($exam, $class) {
            $classes = $this->lockClasses([$class->id]);
            $locked = $this->exams->lockExam($exam);

            // Membership first: a class the exam isn't held for is a 404 whatever its curriculum.
            abort_unless(in_array($class->id, $this->exams->classIds($locked), true), 404, 'This exam is not held for the class.');

            $snapshots = $this->snapshotsFor($classes, [$class->id], null, 'class_id');
            abort_if(
                $this->exams->hasMarksForClass($locked, $class->id),
                409,
                'Marks have already been entered for this class, so its schedule cannot be regenerated.'
            );

            $this->exams->replaceClassSubjects($locked, $classes->first(), $snapshots[$class->id]);

            return $locked;
        });

        return $this->find($exam);
    }

    /**
     * @param  list<int>  $ids
     * @return \Illuminate\Database\Eloquent\Collection<int, Classes>
     */
    private function lockClasses(array $ids)
    {
        $classes = $this->exams->classesByIds($ids);

        // Ascending id order, like every other writer that takes more than one class lock.
        foreach ($classes as $class) {
            $this->curriculum->lockClass($class);
        }

        return $classes;
    }

    /**
     * The curriculum snapshot of each class, read under its lock: class id => rows ready
     * for ExamRepositoryInterface::replaceClassSubjects(). A class with no curriculum or a
     * subject without any marks part is a 422 (keyed `class_ids.N` of the payload, or
     * $field when given), reported for every offending class at once.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Classes>  $classes
     * @param  list<int>  $ids  the ids being generated, to find each class's position
     * @param  list<int>|null  $payloadIds  the full class_ids payload, for the error key
     * @return array<int, list<array<string, mixed>>>
     */
    private function snapshotsFor($classes, array $ids, ?array $payloadIds = null, ?string $field = null): array
    {
        $payloadIds ??= $ids;
        $snapshots = [];
        $errors = [];

        foreach ($classes as $class) {
            $key = $field ?? 'class_ids.'.(int) array_search($class->id, $payloadIds, true);
            $rows = $this->curriculum->forClass($class);

            if ($rows->isEmpty()) {
                $errors[$key][] = "{$class->name} has no subjects in its curriculum. Set its curriculum first.";

                continue;
            }

            foreach ($rows as $row) {
                if (collect(ClassSubject::PARTS)->every(fn (string $part) => $row->{"{$part}_full"} === null)) {
                    $errors[$key][] = "{$row->subject->name} in {$class->name} has no marks part set. Fix the curriculum first.";
                }
            }

            $snapshots[$class->id] = $rows->values()->map(fn (ClassSubject $row, int $position) => [
                'subject_id' => $row->subject_id,
                'group' => $row->group,
                'type' => $row->type,
                'paper_group' => $row->paper_group,
                'written_full' => $row->written_full,
                'written_pass' => $row->written_pass,
                'mcq_full' => $row->mcq_full,
                'mcq_pass' => $row->mcq_pass,
                'practical_full' => $row->practical_full,
                'practical_pass' => $row->practical_pass,
                'sort_order' => $position,
            ])->all();
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $snapshots;
    }

    /**
     * At least one of the two names, checked against the model with the input applied so a
     * partial update is still caught (the same pattern as StaffService).
     */
    private function ensureHasName(Exam $exam): void
    {
        if (! filled($exam->name_en) && ! filled($exam->name_bn)) {
            throw ValidationException::withMessages([
                'name_en' => ['Either the English or Bangla name is required.'],
            ]);
        }
    }

    /**
     * Both dates fall inside the academic year, and the end is on or after the start.
     */
    private function ensureDatesWithinYear(Exam $exam, AcademicYear $year): void
    {
        $errors = [];
        $start = $exam->start_date?->toDateString();
        $end = $exam->end_date?->toDateString();
        $yearStart = $year->start_date->toDateString();
        $yearEnd = $year->end_date->toDateString();

        if ($start !== null && ($start < $yearStart || $start > $yearEnd)) {
            $errors['start_date'][] = "The start date must be within the academic year ({$yearStart} to {$yearEnd}).";
        }

        if ($end !== null && ($end < $yearStart || $end > $yearEnd)) {
            $errors['end_date'][] = "The end date must be within the academic year ({$yearStart} to {$yearEnd}).";
        }

        if ($start !== null && $end !== null && $end < $start) {
            $errors['end_date'][] = 'The end date must be on or after the start date.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The unique rule runs before the write, so a concurrent request can still hit the
     * index (see SubjectService::withUniqueCode()).
     */
    private function withUniqueCode(callable $write): Exam
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            if (UniqueViolation::is($e, 'exams', ['academic_year_id', 'code'])) {
                throw ValidationException::withMessages(['code' => ['The code has already been taken for this academic year.']]);
            }

            throw $e;
        }
    }
}
