<?php

namespace App\Repositories\Eloquent;

use App\Models\Subject;
use App\Repositories\Contracts\SubjectRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

class SubjectRepository extends EloquentRepository implements SubjectRepositoryInterface
{
    protected string $model = Subject::class;

    public function isUsedInExamSchedules(Subject $subject): bool
    {
        return $subject->examSchedules()->exists();
    }

    protected function query(): Builder
    {
        return Subject::query()->orderBy('name');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];

            // Grouped so the OR can't escape other filters.
            $query->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"));
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query;
    }
}
