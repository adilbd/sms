<?php

namespace App\Repositories\Eloquent;

use App\Models\Homework;
use App\Repositories\Contracts\HomeworkRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class HomeworkRepository extends EloquentRepository implements HomeworkRepositoryInterface
{
    protected string $model = Homework::class;

    public function forSectionSubjects(int $sectionId, int $academicYearId, array $subjectIds, array $filters = []): Collection
    {
        if ($subjectIds === []) {
            return new Collection;
        }

        return $this->applyFilters($this->query(), $filters)
            ->reorder()
            ->where('homework.section_id', $sectionId)
            ->where('homework.academic_year_id', $academicYearId)
            ->whereIn('homework.subject_id', $subjectIds)
            ->orderBy('homework.due_on')
            ->orderBy('homework.id')
            ->get();
    }

    protected function query(): Builder
    {
        return parent::query()
            ->with(['subject', 'staff', 'section.class', 'section.shift'])
            ->orderByDesc('homework.due_on')
            ->orderByDesc('homework.id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach (['academic_year_id', 'section_id', 'subject_id'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->where("homework.{$column}", $filters[$column]);
            }
        }

        if (filled($filters['from'] ?? null)) {
            $query->where('homework.due_on', '>=', $filters['from']);
        }

        if (filled($filters['to'] ?? null)) {
            $query->where('homework.due_on', '<=', $filters['to']);
        }

        if (filled($filters['due'] ?? null) && filled($filters['today'] ?? null)) {
            $filters['due'] === 'upcoming'
                ? $query->where('homework.due_on', '>=', $filters['today'])
                : $query->where('homework.due_on', '<', $filters['today']);
        }

        // Built by the service from the signed-in teacher, never from input.
        if (is_array($filters['scope_section_ids'] ?? null)) {
            $query->whereIn('homework.section_id', $filters['scope_section_ids']);
        }

        return $query;
    }
}
