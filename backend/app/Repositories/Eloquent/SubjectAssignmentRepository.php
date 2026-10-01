<?php

namespace App\Repositories\Eloquent;

use App\Models\ClassSubject;
use App\Models\Section;
use App\Models\SubjectAssignment;
use App\Repositories\Contracts\SubjectAssignmentRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class SubjectAssignmentRepository extends EloquentRepository implements SubjectAssignmentRepositoryInterface
{
    protected string $model = SubjectAssignment::class;

    public function lockSection(Section $section): Section
    {
        return Section::query()->whereKey($section->getKey())->lockForUpdate()->firstOrFail();
    }

    public function curriculumSubjectIds(int $classId): array
    {
        return ClassSubject::query()->where('class_id', $classId)->distinct()->pluck('subject_id')
            ->map(fn ($id) => (int) $id)->all();
    }

    public function findFor(int $sectionId, int $subjectId, int $academicYearId): ?SubjectAssignment
    {
        return SubjectAssignment::query()
            ->where('section_id', $sectionId)
            ->where('subject_id', $subjectId)
            ->where('academic_year_id', $academicYearId)
            ->first();
    }

    public function forSectionAndYear(Section $section, int $academicYearId): Collection
    {
        return $this->query()
            ->where('section_id', $section->id)
            ->where('academic_year_id', $academicYearId)
            ->get();
    }

    public function replaceForSection(Section $section, int $academicYearId, array $subjectStaff): void
    {
        SubjectAssignment::query()
            ->where('section_id', $section->id)
            ->where('academic_year_id', $academicYearId)
            ->whereNotIn('subject_id', array_keys($subjectStaff))
            ->delete();

        foreach ($subjectStaff as $subjectId => $staffId) {
            SubjectAssignment::query()->updateOrCreate(
                ['section_id' => $section->id, 'subject_id' => $subjectId, 'academic_year_id' => $academicYearId],
                ['class_id' => $section->class_id, 'staff_id' => $staffId],
            );
        }
    }

    public function userHoldsAssignment(int $userId, int $sectionId, int $subjectId, int $academicYearId): bool
    {
        return SubjectAssignment::query()
            ->where('section_id', $sectionId)
            ->where('subject_id', $subjectId)
            ->where('academic_year_id', $academicYearId)
            ->whereHas('staff', fn (Builder $q) => $q->where('user_id', $userId))
            ->exists();
    }

    protected function query(): Builder
    {
        return parent::query()
            ->with(['staff', 'subject', 'section.class'])
            ->orderBy('section_id')
            ->orderBy('subject_id')
            ->orderBy('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach (['academic_year_id', 'class_id', 'section_id', 'staff_id', 'subject_id'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->where($column, (int) $filters[$column]);
            }
        }

        return $query;
    }
}
