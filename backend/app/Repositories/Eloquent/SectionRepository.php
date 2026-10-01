<?php

namespace App\Repositories\Eloquent;

use App\Models\Section;
use App\Repositories\Contracts\SectionRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

class SectionRepository extends EloquentRepository implements SectionRepositoryInterface
{
    protected string $model = Section::class;

    public function hasStudents(Section $section): bool
    {
        return $section->enrolments()->exists();
    }

    public function hasAttendances(Section $section): bool
    {
        return $section->attendances()->exists();
    }

    public function hasSubjectAssignments(Section $section): bool
    {
        return $section->subjectAssignments()->exists();
    }

    protected function query(): Builder
    {
        // A left join (shift_id stays nullable in the database for legacy rows, see
        // the migration) so ordering by the class's number and the shift's sort_order
        // doesn't drop sections that predate either column.
        return parent::query()
            ->select('sections.*')
            ->leftJoin('classes', 'classes.id', '=', 'sections.class_id')
            ->leftJoin('shifts', 'shifts.id', '=', 'sections.shift_id')
            ->with(['class', 'shift'])
            ->orderBy('classes.number')
            ->orderBy('shifts.sort_order')
            ->orderBy('sections.code')
            ->orderBy('sections.id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];

            $query->where(fn (Builder $q) => $q
                ->where('sections.name', 'like', "%{$search}%")
                ->orWhere('sections.code', 'like', "%{$search}%"));
        }

        // Built by the controller from the signed-in teacher, never from input.
        if (is_array($filters['scope_section_ids'] ?? null)) {
            $query->whereIn('sections.id', $filters['scope_section_ids']);
        }

        if (filled($filters['class_id'] ?? null)) {
            $query->where('sections.class_id', $filters['class_id']);
        }

        if (filled($filters['shift_id'] ?? null)) {
            $query->where('sections.shift_id', $filters['shift_id']);
        }

        if (filled($filters['group'] ?? null)) {
            $query->where('sections.group', $filters['group']);
        }

        if (filled($filters['is_active'] ?? null)) {
            $query->where('sections.is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query;
    }
}
