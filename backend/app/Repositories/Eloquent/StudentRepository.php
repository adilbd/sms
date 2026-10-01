<?php

namespace App\Repositories\Eloquent;

use App\Models\Student;
use App\Models\User;
use App\Repositories\Contracts\StudentRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class StudentRepository extends EloquentRepository implements StudentRepositoryInterface
{
    protected string $model = Student::class;

    public function nextStudentId(int $year): string
    {
        // The sequence is at least 4 digits and keeps growing past 9999 (5+ digits), so
        // the longest ID sorts highest numerically, then the largest of that length.
        $latest = Student::withTrashed()
            ->where('student_id', 'like', "{$year}%")
            ->whereRaw('length(student_id) >= ?', [strlen((string) $year) + 4])
            ->orderByRaw('length(student_id) desc')
            ->orderByDesc('student_id')
            ->value('student_id');

        $sequence = $latest ? (int) substr((string) $latest, strlen((string) $year)) + 1 : 1;

        return $year.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    public function loadDetail(Student $student, ?int $academicYearId): Student
    {
        return $student->load($this->detailRelations($academicYearId));
    }

    public function findByUser(User $user, ?int $academicYearId): ?Student
    {
        return Student::query()->where('user_id', $user->id)->with($this->detailRelations($academicYearId))->first();
    }

    public function childrenOf(User $guardian, ?int $academicYearId): Collection
    {
        return Student::query()
            ->where('guardian_user_id', $guardian->id)
            ->with($this->detailRelations($academicYearId))
            ->orderBy('student_id')
            ->get();
    }

    public function hasActiveChildren(User $guardian): bool
    {
        return Student::query()
            ->where('guardian_user_id', $guardian->id)
            ->where('status', Student::STATUS_ACTIVE)
            ->exists();
    }

    public function hasAttendances(Student $student): bool
    {
        return $student->attendances()->exists();
    }

    public function hasExamResults(Student $student): bool
    {
        return $student->examResults()->exists();
    }

    public function hasFeePayments(Student $student): bool
    {
        return $student->feePayments()->exists();
    }

    protected function query(): Builder
    {
        return parent::query()->with('user');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];

            // Grouped so the ORs can't escape the other filters.
            $query->where(fn (Builder $q) => $q
                ->where('students.name_en', 'like', "%{$search}%")
                ->orWhere('students.name_bn', 'like', "%{$search}%")
                ->orWhere('students.student_id', 'like', "%{$search}%")
                // Only for callers who may see these fields, or search would leak them.
                ->when($filters['search_sensitive'] ?? false, fn (Builder $q) => $q
                    ->orWhere('students.guardian_mobile', 'like', "%{$search}%")
                    ->orWhere('students.birth_registration_number', 'like', "%{$search}%")));
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('students.status', $filters['status']);
        }

        $yearId = (int) ($filters['academic_year_id'] ?? 0);

        if ($yearId > 0) {
            // The list is "who was enrolled in this year", ordered like a class roll.
            $query->select('students.*')
                ->join('student_enrolments as se', fn ($join) => $join
                    ->on('se.student_id', '=', 'students.id')
                    ->where('se.academic_year_id', '=', $yearId))
                ->join('classes as c', 'c.id', '=', 'se.class_id')
                ->join('sections as s', 's.id', '=', 'se.section_id')
                ->with(['currentEnrolment' => fn ($q) => $q
                    ->where('academic_year_id', $yearId)
                    ->with(['class', 'section', 'optionalSubject'])])
                ->orderBy('c.number')
                ->orderBy('s.code')
                ->orderBy('se.roll_number')
                ->orderBy('students.id');

            foreach (['class_id', 'section_id', 'group'] as $column) {
                if (filled($filters[$column] ?? null)) {
                    $query->where("se.{$column}", $filters[$column]);
                }
            }

            if (filled($filters['shift_id'] ?? null)) {
                $query->where('s.shift_id', $filters['shift_id']);
            }

            return $query;
        }

        // No year to list (no active academic year yet): filter on any enrolment.
        foreach (['class_id', 'section_id', 'group'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->whereHas('enrolments', fn (Builder $q) => $q->where($column, $filters[$column]));
            }
        }

        if (filled($filters['shift_id'] ?? null)) {
            $query->whereHas('enrolments.section', fn (Builder $q) => $q->where('shift_id', $filters['shift_id']));
        }

        return $query->orderBy('students.student_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function detailRelations(?int $academicYearId): array
    {
        $enrolmentRelations = ['academicYear', 'class', 'section', 'optionalSubject'];

        return [
            'user',
            'enrolments' => fn ($q) => $q->with($enrolmentRelations)->newestYearFirst(),
            'currentEnrolment' => fn ($q) => $q
                ->where('academic_year_id', $academicYearId ?? 0)
                ->with($enrolmentRelations),
        ];
    }
}
