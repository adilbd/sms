<?php

namespace App\Repositories\Eloquent;

use App\Models\Staff;
use App\Repositories\Contracts\StaffRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class StaffRepository extends EloquentRepository implements StaffRepositoryInterface
{
    protected string $model = Staff::class;

    public function hasActiveInPosition(int $shiftId, string $position, ?int $exceptId): bool
    {
        // A locking read: under InnoDB's default REPEATABLE READ isolation, a plain
        // read inside a transaction is answered from that transaction's snapshot (taken
        // at its first read), which could predate another transaction's concurrent
        // commit of a new active head for this shift. lockForUpdate() always reads the
        // latest committed row version instead, so this still catches that commit even
        // when this transaction's snapshot was already fixed by an earlier plain read
        // (see StaffService::update()'s shiftIdsFor() call).
        return Staff::query()
            ->where('position', $position)
            ->where('status', Staff::STATUS_ACTIVE)
            ->whereHas('shifts', fn (Builder $q) => $q->where('shifts.id', $shiftId))
            ->when($exceptId, fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->lockForUpdate()
            ->exists();
    }

    public function findByUserId(int $userId): ?Staff
    {
        return Staff::query()->where('user_id', $userId)->first();
    }

    public function syncShifts(Staff $staff, array $shiftIds): void
    {
        $staff->shifts()->sync($shiftIds);
    }

    public function shiftIdsFor(Staff $staff): array
    {
        // Locks the pivot rows too, so a concurrent request can't change this staff
        // member's shifts out from under this transaction between this read and the
        // write later in the same transaction.
        return $staff->shifts()->lockForUpdate()->pluck('shifts.id')->map(fn ($id) => (int) $id)->all();
    }

    public function hasSubjectAssignments(Staff $staff): bool
    {
        return $staff->subjectAssignments()->exists();
    }

    public function isClassTeacher(Staff $staff): bool
    {
        return $staff->classSections()->exists();
    }

    public function belongsToShift(Staff $staff, int $shiftId): bool
    {
        return $staff->shifts()->where('shifts.id', $shiftId)->exists();
    }

    public function syncEducations(Staff $staff, array $rows): void
    {
        $this->syncChildRows($staff, 'educations', $rows, [
            'degree', 'institution', 'board_university', 'passing_year', 'result',
        ]);
    }

    public function syncTrainings(Staff $staff, array $rows): void
    {
        $this->syncChildRows($staff, 'trainings', $rows, [
            'title', 'organizer', 'duration', 'year',
        ]);
    }

    public function publicList(array $filters, bool $withFullProfile = false): Collection
    {
        // The head/assistant-head pages render each result's full profile inline
        // (education, training), so eager-load those too rather than lazy-loading per
        // member. List pages only render a card (photo/name/designation/shifts), so
        // skip the extra queries there.
        $query = Staff::published()
            ->with($withFullProfile ? ['shifts', 'educations', 'trainings'] : ['shifts'])
            ->orderBy('sort_order')->orderBy('id');

        if (filled($filters['position'] ?? null)) {
            $query->where('position', $filters['position']);
        }

        if (array_key_exists('former', $filters) && $filters['former'] !== null) {
            $filters['former'] ? $query->former() : $query->active();
        }

        if (filled($filters['shift_id'] ?? null)) {
            $query->whereHas('shifts', fn (Builder $q) => $q->where('shifts.id', $filters['shift_id']));
        }

        return $query->get();
    }

    public function findPublished(int $id): Staff
    {
        return Staff::published()->with(['shifts', 'educations', 'trainings'])->findOrFail($id);
    }

    public function publishedForSitemap(): Collection
    {
        return Staff::published()->select(['id', 'updated_at'])->orderBy('id')->get();
    }

    public function isInRoutine(Staff $staff): bool
    {
        return $staff->routineSlots()->exists();
    }

    public function hasHomework(Staff $staff): bool
    {
        return $staff->homework()->exists();
    }

    protected function query(): Builder
    {
        return parent::query()
            ->with(['shifts', 'educations', 'trainings', 'user.roles'])
            ->orderBy('sort_order')->orderBy('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];

            $query->where(fn (Builder $q) => $q
                ->where('name_en', 'like', "%{$search}%")
                ->orWhere('name_bn', 'like', "%{$search}%")
                ->orWhere('designation', 'like', "%{$search}%")
                ->orWhere('employee_id', 'like', "%{$search}%"));
        }

        if (filled($filters['category'] ?? null)) {
            $query->where('category', $filters['category']);
        }

        if (filled($filters['position'] ?? null)) {
            $query->where('position', $filters['position']);
        }

        if (filled($filters['is_active'] ?? null)) {
            filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN)
                ? $query->active()
                : $query->former();
        }

        if (filled($filters['shift'] ?? null)) {
            $query->whereHas('shifts', fn (Builder $q) => $q->where('shifts.id', $filters['shift']));
        }

        return $query;
    }

    /**
     * Sync a staff member's child rows (educations/trainings) from a validated array,
     * mirroring GalleryRepository::syncItems(): update/create rows that were sent (in
     * their new order), delete rows that were not.
     *
     * Re-calls $staff->{$relation}() for every query rather than reusing one builder:
     * HasMany forwards where()/whereKey() etc. to the same underlying query instance,
     * so reusing it would make each loop iteration's whereKey() stack onto the last,
     * narrowing every subsequent call (including the final delete) instead of running
     * independently.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $columns
     */
    private function syncChildRows(Staff $staff, string $relation, array $rows, array $columns): void
    {
        // Cast to int: a multipart request (see StaffForm.vue) sends every field,
        // including this id, as a string, so a strict in_array() against the
        // integer ids pluck() returns would never match and every save would delete
        // and recreate every row instead of updating it.
        $existingIds = $staff->{$relation}()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $keepIds = [];

        foreach ($rows as $position => $row) {
            $attributes = collect($columns)->mapWithKeys(fn ($column) => [$column => $row[$column] ?? null])->all();
            $attributes['sort_order'] = $position;

            $id = filled($row['id'] ?? null) ? (int) $row['id'] : null;

            if ($id && in_array($id, $existingIds, true)) {
                $staff->{$relation}()->whereKey($id)->update($attributes);
                $keepIds[] = $id;
            } else {
                $keepIds[] = $staff->{$relation}()->create($attributes)->id;
            }
        }

        // Anything not present in this batch (including every existing row, when
        // $rows is empty) is removed.
        $staff->{$relation}()->whereNotIn('id', $keepIds)->delete();
    }
}
