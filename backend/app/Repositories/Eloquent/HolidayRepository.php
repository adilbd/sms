<?php

namespace App\Repositories\Eloquent;

use App\Models\Holiday;
use App\Repositories\Contracts\HolidayRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class HolidayRepository extends EloquentRepository implements HolidayRepositoryInterface
{
    protected string $model = Holiday::class;

    public function findByDate(string $date): ?Holiday
    {
        return Holiday::query()->where('date', $date)->first();
    }

    public function between(string $from, string $to): Collection
    {
        return Holiday::query()
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get();
    }

    protected function query(): Builder
    {
        return parent::query()->orderBy('date')->orderBy('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (filled($filters['academic_year_id'] ?? null)) {
            $query->where('academic_year_id', $filters['academic_year_id']);
        }

        return $query;
    }
}
