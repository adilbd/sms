<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\RepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent implementation of the base contract. Subclasses set $model and
 * override applyFilters() (and optionally query()) for model-specific behavior.
 */
abstract class EloquentRepository implements RepositoryInterface
{
    /** @var class-string<Model> */
    protected string $model;

    public function find(int $id): ?Model
    {
        return $this->query()->find($id);
    }

    public function findOrFail(int $id): Model
    {
        return $this->query()->findOrFail($id);
    }

    public function paginate(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->applyFilters($this->query(), $filters)->paginate($perPage);
    }

    public function create(array $attributes): Model
    {
        return $this->model::create($attributes);
    }

    public function update(Model $model, array $attributes): Model
    {
        $model->update($attributes);

        return $model;
    }

    public function delete(Model $model): void
    {
        $model->delete();
    }

    /**
     * Base query for reads. Override to add default eager loads or ordering.
     */
    protected function query(): Builder
    {
        return $this->model::query();
    }

    /**
     * Apply list filters. Override per model; ignore keys you don't support.
     *
     * @param  array<string, mixed>  $filters
     */
    protected function applyFilters(Builder $query, array $filters): Builder
    {
        return $query;
    }
}
