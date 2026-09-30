<?php

namespace App\Repositories\Eloquent;

use App\Models\AcademicYear;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;

class AcademicYearRepository extends EloquentRepository implements AcademicYearRepositoryInterface
{
    protected string $model = AcademicYear::class;

    public function hasStudents(AcademicYear $academicYear): bool
    {
        return $academicYear->students()->exists();
    }

    public function hasExams(AcademicYear $academicYear): bool
    {
        return $academicYear->exams()->exists();
    }

    public function hasFeeStructures(AcademicYear $academicYear): bool
    {
        return $academicYear->feeStructures()->exists();
    }

    public function hasClassTeacherRows(AcademicYear $academicYear): bool
    {
        return $academicYear->classSections()->exists();
    }

    public function hasSubjectAssignments(AcademicYear $academicYear): bool
    {
        return $academicYear->subjectAssignments()->exists();
    }

    public function deactivateAllExcept(AcademicYear $academicYear): void
    {
        AcademicYear::where('id', '!=', $academicYear->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    protected function query(): Builder
    {
        return parent::query()->orderBy('year', 'desc')->orderBy('id', 'desc');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (filled($filters['is_active'] ?? null)) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query;
    }
}
