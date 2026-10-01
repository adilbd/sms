<?php

namespace App\Repositories\Eloquent;

use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Subject;
use App\Models\SubjectAssignment;
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
        $subjects = Subject::withTrashed()->whereIn('id', array_column($rows, 'subject_id'))->get()->keyBy('id');

        $keep = [];

        foreach (array_values($rows) as $position => $row) {
            $key = $this->key($row['subject_id'], $row['group'] ?? null);
            $attributes = ['type' => $row['type'], 'sort_order' => $position];
            $current = $existing->get($key);

            if (array_key_exists('paper_group', $row)) {
                $attributes['paper_group'] = $row['paper_group'];
            }

            if (array_intersect(ClassSubject::MARK_FIELDS, array_keys($row)) !== []) {
                foreach (ClassSubject::MARK_FIELDS as $field) {
                    $attributes[$field] = $row[$field] ?? null;
                }
            } elseif (! $current) {
                // A new row that sends no marks starts from its subject's defaults.
                $subject = $subjects->get($row['subject_id']);
                $attributes['written_full'] = $subject?->total_marks;
                $attributes['written_pass'] = $subject?->pass_marks;
            }

            if ($current) {
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

    public function savedPaperGroups(Classes $class): array
    {
        return $class->curriculum()->whereNotNull('paper_group')->get()
            ->mapWithKeys(fn (ClassSubject $row) => [$this->key($row->subject_id, $row->group) => $row->paper_group])
            ->all();
    }

    public function assignedSubjects(Classes $class): array
    {
        return Subject::withTrashed()
            ->whereIn('id', SubjectAssignment::query()->where('class_id', $class->id)->select('subject_id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function lockClass(Classes $class): Classes
    {
        return Classes::query()->whereKey($class->getKey())->lockForUpdate()->firstOrFail();
    }

    public function unusableRowIndexes(Classes $class, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $subjectIds = array_values(array_unique(array_column($rows, 'subject_id')));
        $usable = Subject::query()->whereIn('id', $subjectIds)->where('is_active', true)->pluck('id')->all();
        $existing = $class->curriculum()->whereIn('subject_id', $subjectIds)->get()
            ->mapWithKeys(fn (ClassSubject $row) => [$this->key($row->subject_id, $row->group) => true]);

        $unusable = [];

        foreach ($rows as $i => $row) {
            if (! in_array($row['subject_id'], $usable, true) && ! $existing->has($this->key($row['subject_id'], $row['group'] ?? null))) {
                $unusable[] = $i;
            }
        }

        return $unusable;
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
