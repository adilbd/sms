<?php

namespace App\Services;

use App\Models\Subject;
use App\Repositories\Contracts\SubjectRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
        return $this->subjects->create($data);
    }

    public function update(Subject $subject, array $data): Subject
    {
        return $this->subjects->update($subject, $data);
    }

    public function delete(Subject $subject): void
    {
        // exam_schedules.subject_id is restrictOnDelete, but soft deletes bypass the constraint.
        abort_if(
            $this->subjects->isUsedInExamSchedules($subject),
            409,
            'Subject is used in exam schedules and cannot be deleted.'
        );

        $this->subjects->delete($subject);
    }
}
