<?php

namespace App\Services;

use App\Models\Subject;
use App\Repositories\Contracts\SubjectRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

class SubjectService
{
    public function __construct(private SubjectRepositoryInterface $subjects) {}

    /**
     * @param  array{search?: string, is_active?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->subjects->paginate($filters, $perPage);
    }

    public function create(array $data): Subject
    {
        // A new model carries the column defaults, so omitted marks are checked too.
        $this->ensurePassMarksWithinTotal(new Subject($data));

        return $this->withUniqueCode(fn () => $this->subjects->create($data));
    }

    public function update(Subject $subject, array $data): Subject
    {
        // Compare against saved values when only one of the two marks is sent. Skip the
        // check when neither is sent, so older rows can still be renamed or deactivated.
        if (array_key_exists('pass_marks', $data) || array_key_exists('total_marks', $data)) {
            $this->ensurePassMarksWithinTotal((clone $subject)->fill($data));
        }

        return $this->withUniqueCode(fn () => $this->subjects->update($subject, $data));
    }

    public function delete(Subject $subject): void
    {
        // Foreign keys don't protect soft-deleted rows, so check references here.
        abort_if(
            $this->subjects->isUsedInExamSchedules($subject),
            409,
            'Subject is used in exam schedules and cannot be deleted.'
        );

        abort_if(
            $this->subjects->hasTeacherAssignments($subject),
            409,
            'Subject is assigned to teachers and cannot be deleted.'
        );

        $this->subjects->delete($subject);
    }

    private function ensurePassMarksWithinTotal(Subject $subject): void
    {
        if ($subject->pass_marks > $subject->total_marks) {
            throw ValidationException::withMessages([
                'pass_marks' => ['The pass marks must not be greater than the total marks.'],
            ]);
        }
    }

    /**
     * The unique rule runs before the write, so a concurrent request can still hit the
     * database index. Report that the same way as the validation rule would.
     */
    private function withUniqueCode(callable $write): Subject
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'code' => ['The code has already been taken.'],
            ]);
        }
    }
}
