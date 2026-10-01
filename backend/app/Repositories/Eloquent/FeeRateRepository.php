<?php

namespace App\Repositories\Eloquent;

use App\Models\FeeDue;
use App\Models\FeeRate;
use App\Models\StudentEnrolment;
use App\Repositories\Contracts\FeeRateRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class FeeRateRepository extends EloquentRepository implements FeeRateRepositoryInterface
{
    protected string $model = FeeRate::class;

    public function existsFor(int $headId, int $classId, int $academicYearId, ?string $group, ?int $exceptId): bool
    {
        return FeeRate::query()
            ->where('fee_head_id', $headId)
            ->where('class_id', $classId)
            ->where('academic_year_id', $academicYearId)
            ->when($group === null, fn (Builder $q) => $q->whereNull('group'), fn (Builder $q) => $q->where('group', $group))
            ->when($exceptId, fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->exists();
    }

    public function hasDues(FeeRate $rate): bool
    {
        return FeeDue::query()
            ->where('fee_head_id', $rate->fee_head_id)
            ->whereIn('enrolment_id', StudentEnrolment::query()
                ->select('id')
                ->where('class_id', $rate->class_id)
                ->where('academic_year_id', $rate->academic_year_id))
            ->exists();
    }

    public function forGeneration(int $academicYearId, array $headIds, array $classIds): Collection
    {
        return FeeRate::query()
            ->where('academic_year_id', $academicYearId)
            ->whereIn('fee_head_id', $headIds)
            ->whereIn('class_id', $classIds)
            ->get();
    }

    public function loadDetail(FeeRate $rate): FeeRate
    {
        return $rate->load(['head', 'class']);
    }

    protected function query(): Builder
    {
        return parent::query()
            ->with(['head', 'class'])
            ->orderBy('academic_year_id')
            ->orderBy('class_id')
            ->orderBy('fee_head_id')
            ->orderBy('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach (['academic_year_id', 'class_id', 'fee_head_id'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->where($column, $filters[$column]);
            }
        }

        return $query;
    }
}
