<?php

namespace App\Repositories\Eloquent;

use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Repositories\Contracts\ClassSubjectRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClassSubjectRepository implements ClassSubjectRepositoryInterface
{
    public function forClass(Classes $class): Collection
    {
        return $this->ordered($class->curriculum())->get();
    }

    public function forClassAndGroup(Classes $class, string $group): Collection
    {
        return $this->ordered(
            $class->curriculum()->where(fn (Builder $q) => $q->whereNull('group')->orWhere('group', $group))
        )->get();
    }

    public function sync(Classes $class, array $rows): void
    {
        $existing = $class->curriculum()->get()->keyBy(fn (ClassSubject $row) => $this->key($row->subject_id, $row->group));

        $keep = [];

        foreach (array_values($rows) as $position => $row) {
            $key = $this->key($row['subject_id'], $row['group'] ?? null);
            $attributes = ['type' => $row['type'], 'sort_order' => $position];

            if ($current = $existing->get($key)) {
                $current->update($attributes);
                $keep[] = $current->id;
            } else {
                // A fresh relation for every write: HasMany forwards where()/whereKey()
                // onto one shared query instance (see StaffRepository::syncEducations()).
                $keep[] = $class->curriculum()->create([
                    'subject_id' => $row['subject_id'],
                    'group' => $row['group'] ?? null,
                    ...$attributes,
                ])->id;
            }
        }

        $class->curriculum()->whereNotIn('id', $keep)->delete();
    }

    public function unusableSubjectIds(Classes $class, array $subjectIds): array
    {
        if ($subjectIds === []) {
            return [];
        }

        $usable = Subject::query()->whereIn('id', $subjectIds)->where('is_active', true)->pluck('id');
        $inCurriculum = $class->curriculum()->whereIn('subject_id', $subjectIds)->pluck('subject_id');

        return array_values(array_diff($subjectIds, $usable->all(), $inCurriculum->all()));
    }

    private function ordered(HasMany $relation): HasMany
    {
        return $relation->with('subject')->orderBy('sort_order')->orderBy('id');
    }

    private function key(int $subjectId, ?string $group): string
    {
        return $subjectId.'|'.($group ?? '');
    }
}
