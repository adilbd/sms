<?php

namespace App\Repositories\Eloquent;

use App\Models\StudentFeeWaiver;
use App\Repositories\Contracts\FeeWaiverRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class FeeWaiverRepository extends EloquentRepository implements FeeWaiverRepositoryInterface
{
    protected string $model = StudentFeeWaiver::class;

    public function existsFor(int $studentId, int $academicYearId, int $headId, ?int $exceptId): bool
    {
        return StudentFeeWaiver::query()
            ->where('student_id', $studentId)
            ->where('academic_year_id', $academicYearId)
            ->where('fee_head_id', $headId)
            ->when($exceptId, fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->exists();
    }

    public function forGeneration(int $academicYearId, array $studentIds): Collection
    {
        return StudentFeeWaiver::query()
            ->where('academic_year_id', $academicYearId)
            ->whereIn('student_id', $studentIds)
            ->get();
    }

    public function loadDetail(StudentFeeWaiver $waiver): StudentFeeWaiver
    {
        return $waiver->load(['head', 'approver']);
    }

    protected function query(): Builder
    {
        return parent::query()->with(['head', 'approver'])->orderByDesc('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach (['student_id', 'academic_year_id', 'fee_head_id'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->where($column, $filters[$column]);
            }
        }

        return $query;
    }
}
