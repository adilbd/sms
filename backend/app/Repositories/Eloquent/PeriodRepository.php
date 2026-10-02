<?php

namespace App\Repositories\Eloquent;

use App\Models\Period;
use App\Repositories\Contracts\PeriodRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PeriodRepository extends EloquentRepository implements PeriodRepositoryInterface
{
    protected string $model = Period::class;

    public function findOverlapping(int $shiftId, string $start, string $end, ?int $exceptId = null): ?Period
    {
        return Period::query()
            ->where('shift_id', $shiftId)
            ->where('start_time', '<', $end)
            ->where('end_time', '>', $start)
            ->when($exceptId !== null, fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->orderBy('number')
            ->first();
    }

    public function isUsedInRoutine(Period $period): bool
    {
        return $period->routineSlots()->exists();
    }

    public function lockForUpdate(Period $period): Period
    {
        return Period::query()->whereKey($period->id)->lockForUpdate()->firstOrFail();
    }

    public function forShift(int $shiftId): Collection
    {
        return Period::query()->where('shift_id', $shiftId)->orderBy('number')->orderBy('id')->get();
    }

    protected function query(): Builder
    {
        return parent::query()->orderBy('shift_id')->orderBy('number')->orderBy('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (filled($filters['shift_id'] ?? null)) {
            $query->where('shift_id', (int) $filters['shift_id']);
        }

        return $query;
    }
}
