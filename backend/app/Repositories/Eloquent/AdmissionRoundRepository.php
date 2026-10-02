<?php

namespace App\Repositories\Eloquent;

use App\Models\AdmissionApplication;
use App\Models\AdmissionRound;
use App\Models\AdmissionRoundClass;
use App\Repositories\Contracts\AdmissionRoundRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AdmissionRoundRepository extends EloquentRepository implements AdmissionRoundRepositoryInterface
{
    protected string $model = AdmissionRound::class;

    public function open(): Collection
    {
        return AdmissionRound::query()
            ->open()
            ->with(['academicYear', 'classes.class'])
            ->orderBy('closes_at')
            ->orderBy('id')
            ->get();
    }

    public function findOpen(int $id): ?AdmissionRound
    {
        return AdmissionRound::query()
            ->open()
            ->with(['academicYear', 'classes.class'])
            ->find($id);
    }

    public function lockForUpdate(AdmissionRound $round): AdmissionRound
    {
        return AdmissionRound::query()->whereKey($round->id)->lockForUpdate()->firstOrFail();
    }

    public function seatsFor(AdmissionRound $round, int $classId): ?int
    {
        $seats = AdmissionRoundClass::query()
            ->where('round_id', $round->id)
            ->where('class_id', $classId)
            ->value('seats');

        return $seats === null ? null : (int) $seats;
    }

    public function hasApplications(AdmissionRound $round): bool
    {
        return $round->applications()->withTrashed()->exists();
    }

    public function classIdsWithApplications(AdmissionRound $round): array
    {
        return AdmissionApplication::withTrashed()
            ->where('round_id', $round->id)
            ->distinct()
            ->pluck('class_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function syncClasses(AdmissionRound $round, array $classes): void
    {
        $keep = [];

        foreach ($classes as $row) {
            // A fresh query per class: HasMany forwards where() onto one shared builder
            // (see StaffRepository::syncEducations()).
            AdmissionRoundClass::query()->updateOrCreate(
                ['round_id' => $round->id, 'class_id' => $row['class_id']],
                ['seats' => $row['seats'] ?? null],
            );
            $keep[] = $row['class_id'];
        }

        AdmissionRoundClass::query()
            ->where('round_id', $round->id)
            ->whereNotIn('class_id', $keep)
            ->delete();
    }

    public function loadDetail(AdmissionRound $round): AdmissionRound
    {
        return $round->load(['academicYear', 'classes.class'])->loadCount('applications');
    }

    protected function query(): Builder
    {
        return parent::query()
            ->with(['academicYear', 'classes.class'])
            ->withCount('applications')
            ->orderByDesc('opens_at')
            ->orderByDesc('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (filled($filters['academic_year_id'] ?? null)) {
            $query->where('academic_year_id', $filters['academic_year_id']);
        }

        if (filled($filters['is_published'] ?? null)) {
            $query->where('is_published', filter_var($filters['is_published'], FILTER_VALIDATE_BOOLEAN));
        }

        if (filled($filters['search'] ?? null)) {
            $term = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('name_en', 'like', $term)->orWhere('name_bn', 'like', $term));
        }

        return $query;
    }
}
