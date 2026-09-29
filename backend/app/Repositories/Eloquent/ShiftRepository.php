<?php

namespace App\Repositories\Eloquent;

use App\Models\Shift;
use App\Repositories\Contracts\ShiftRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ShiftRepository extends EloquentRepository implements ShiftRepositoryInterface
{
    protected string $model = Shift::class;

    public function hasStaff(Shift $shift): bool
    {
        return $shift->staff()->exists();
    }

    public function activeOrdered(): Collection
    {
        return Shift::active()->orderBy('sort_order')->orderBy('id')->get();
    }

    public function findBySlug(string $slug): ?Shift
    {
        return Shift::where('slug', $slug)->first();
    }

    protected function query(): Builder
    {
        return parent::query()->orderBy('sort_order')->orderBy('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];
            $query->where(fn (Builder $q) => $q
                ->where('name_en', 'like', "%{$search}%")
                ->orWhere('name_bn', 'like', "%{$search}%"));
        }

        if (filled($filters['is_active'] ?? null)) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query;
    }
}
