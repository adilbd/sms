<?php

namespace App\Repositories\Eloquent;

use App\Models\FeeDue;
use App\Repositories\Contracts\FeeDueRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FeeDueRepository extends EloquentRepository implements FeeDueRepositoryInterface
{
    protected string $model = FeeDue::class;

    public function existingKeys(array $enrolmentIds, array $headIds, array $periods): array
    {
        $keys = [];

        foreach (array_chunk($enrolmentIds, 500) as $chunk) {
            FeeDue::query()
                ->whereIn('enrolment_id', $chunk)
                ->whereIn('fee_head_id', $headIds)
                ->whereIn('period', $periods)
                ->get(['enrolment_id', 'fee_head_id', 'period'])
                ->each(function (FeeDue $due) use (&$keys) {
                    $keys["{$due->enrolment_id}|{$due->fee_head_id}|{$due->period}"] = true;
                });
        }

        return $keys;
    }

    public function insertMany(array $rows): int
    {
        $inserted = 0;

        foreach (array_chunk($rows, 500) as $chunk) {
            $inserted += DB::table('fee_dues')->insertOrIgnore($chunk);
        }

        return $inserted;
    }

    public function openForStudent(int $studentId): Collection
    {
        return FeeDue::query()
            ->where('student_id', $studentId)
            ->whereIn('status', [FeeDue::STATUS_UNPAID, FeeDue::STATUS_PARTIAL])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    public function openByIdsForStudent(int $studentId, array $ids): Collection
    {
        $dues = FeeDue::query()
            ->where('student_id', $studentId)
            ->whereIn('status', [FeeDue::STATUS_UNPAID, FeeDue::STATUS_PARTIAL])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        return new Collection(collect($ids)->map(fn (int $id) => $dues->get($id))->filter()->values()->all());
    }

    public function allForStudent(int $studentId): Collection
    {
        return FeeDue::query()
            ->where('student_id', $studentId)
            ->with('head')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    protected function query(): Builder
    {
        return parent::query()
            ->with(['head', 'student', 'enrolment.class', 'enrolment.section'])
            ->orderBy('due_date')
            ->orderBy('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach (['student_id', 'fee_head_id', 'status'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->where($column, $filters[$column]);
            }
        }

        if (filled($filters['academic_year_id'] ?? null) || filled($filters['section_id'] ?? null) || filled($filters['class_id'] ?? null)) {
            $query->whereHas('enrolment', function (Builder $q) use ($filters) {
                foreach (['academic_year_id', 'section_id', 'class_id'] as $column) {
                    if (filled($filters[$column] ?? null)) {
                        $q->where($column, $filters[$column]);
                    }
                }
            });
        }

        if (filled($filters['month'] ?? null)) {
            // Every due falls in some month by its due date (monthly ones by their period).
            $first = Carbon::createFromFormat('Y-m-d', $filters['month'].'-01')->startOfDay();
            $query->whereBetween('due_date', [$first->toDateString(), $first->endOfMonth()->toDateString()]);
        }

        return $query;
    }
}
