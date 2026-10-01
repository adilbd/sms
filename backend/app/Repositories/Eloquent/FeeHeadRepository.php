<?php

namespace App\Repositories\Eloquent;

use App\Models\FeeHead;
use App\Repositories\Contracts\FeeHeadRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class FeeHeadRepository extends EloquentRepository implements FeeHeadRepositoryInterface
{
    protected string $model = FeeHead::class;

    public function hasRates(FeeHead $head): bool
    {
        return $head->rates()->exists();
    }

    public function hasDues(FeeHead $head): bool
    {
        return $head->dues()->exists();
    }

    public function hasWaivers(FeeHead $head): bool
    {
        return $head->waivers()->exists();
    }

    public function active(): Collection
    {
        return FeeHead::query()->where('is_active', true)->orderBy('id')->get();
    }

    protected function query(): Builder
    {
        return parent::query()->orderBy('kind')->orderBy('code')->orderBy('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];
            $query->where(fn (Builder $q) => $q
                ->where('name_en', 'like', "%{$search}%")
                ->orWhere('name_bn', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"));
        }

        if (filled($filters['kind'] ?? null)) {
            $query->where('kind', $filters['kind']);
        }

        if (filled($filters['is_active'] ?? null)) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query;
    }
}
