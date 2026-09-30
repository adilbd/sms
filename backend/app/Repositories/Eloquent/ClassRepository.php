<?php

namespace App\Repositories\Eloquent;

use App\Models\Classes;
use App\Models\ClassSubject;
use App\Repositories\Contracts\ClassRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

class ClassRepository extends EloquentRepository implements ClassRepositoryInterface
{
    protected string $model = Classes::class;

    public function hasSections(Classes $class): bool
    {
        return $class->sections()->exists();
    }

    public function hasGroupedSections(Classes $class): bool
    {
        return $class->sections()->whereNotNull('group')->exists();
    }

    public function hasStudents(Classes $class): bool
    {
        return $class->students()->exists();
    }

    public function hasAttendances(Classes $class): bool
    {
        return $class->attendances()->exists();
    }

    public function hasExamSchedules(Classes $class): bool
    {
        return $class->examSchedules()->exists();
    }

    public function hasFeeStructures(Classes $class): bool
    {
        return $class->feeStructures()->exists();
    }

    public function hasSubjectAssignments(Classes $class): bool
    {
        return $class->subjectAssignments()->exists();
    }

    public function hasGroupedCurriculum(Classes $class): bool
    {
        return $class->curriculum()
            ->where(fn (Builder $q) => $q->whereNotNull('group')->orWhere('type', ClassSubject::TYPE_OPTIONAL))
            ->exists();
    }

    public function deleteCurriculum(Classes $class): void
    {
        $class->curriculum()->delete();
    }

    protected function query(): Builder
    {
        return parent::query()->withCount('sections')->orderBy('number')->orderBy('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];

            $query->where(fn (Builder $q) => $q
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_bn', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"));
        }

        if (filled($filters['is_active'] ?? null)) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (filled($filters['level'] ?? null) && array_key_exists($filters['level'], Classes::LEVEL_RANGES)) {
            [$min, $max] = Classes::LEVEL_RANGES[$filters['level']];
            $query->whereBetween('number', [$min, $max]);
        }

        return $query;
    }
}
