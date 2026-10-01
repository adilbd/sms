<?php

namespace App\Repositories\Eloquent;

use App\Models\Classes;
use App\Models\Section;
use App\Models\StudentEnrolment;
use App\Repositories\Contracts\PromotionRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class PromotionRepository implements PromotionRepositoryInterface
{
    public function activeEnrolments(int $sectionId, int $academicYearId): Collection
    {
        return StudentEnrolment::query()
            ->where('student_enrolments.section_id', $sectionId)
            ->activeIn($academicYearId)
            ->with(['student', 'optionalSubject'])
            ->orderByRaw('student_enrolments.roll_number is null')
            ->orderBy('student_enrolments.roll_number')
            ->orderBy('student_enrolments.id')
            ->get();
    }

    public function enrolledStudentIds(array $studentIds, int $academicYearId): array
    {
        if ($studentIds === []) {
            return [];
        }

        return StudentEnrolment::query()
            ->where('academic_year_id', $academicYearId)
            ->whereIn('student_id', $studentIds)
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function findClassByNumber(int $number): ?Classes
    {
        return Classes::query()->where('number', $number)->first();
    }

    public function findSectionByCodeAndShift(int $classId, string $code, int $shiftId): ?Section
    {
        return Section::query()
            ->where('class_id', $classId)
            ->where('code', $code)
            ->where('shift_id', $shiftId)
            ->where('is_active', true)
            ->first();
    }
}
