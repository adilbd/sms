<?php

namespace App\Repositories\Eloquent;

use App\Models\ClassSection;
use App\Models\Section;
use App\Repositories\Contracts\ClassTeacherRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class ClassTeacherRepository implements ClassTeacherRepositoryInterface
{
    public function forSection(Section $section): Collection
    {
        return ClassSection::where('section_id', $section->id)
            ->with(['staff', 'academicYear'])
            ->orderByDesc('academic_year_id')
            ->get();
    }

    public function upsert(Section $section, int $academicYearId, int $staffId): ClassSection
    {
        $classSection = ClassSection::updateOrCreate(
            ['section_id' => $section->id, 'academic_year_id' => $academicYearId],
            ['class_id' => $section->class_id, 'staff_id' => $staffId]
        );

        return $classSection->load('staff');
    }

    public function deleteForSectionAndYear(Section $section, int $academicYearId): void
    {
        ClassSection::where('section_id', $section->id)
            ->where('academic_year_id', $academicYearId)
            ->delete();
    }

    public function deleteForSection(Section $section): void
    {
        ClassSection::where('section_id', $section->id)->delete();
    }

    public function hasTeacherOutsideShift(Section $section, int $shiftId): bool
    {
        return ClassSection::where('section_id', $section->id)
            ->whereNotNull('staff_id')
            ->whereDoesntHave('staff', fn ($q) => $q->whereHas('shifts', fn ($s) => $s->where('shifts.id', $shiftId)))
            ->exists();
    }

    public function teacherLeadsAnotherSection(int $academicYearId, int $staffId, ?int $exceptSectionId): bool
    {
        return ClassSection::where('academic_year_id', $academicYearId)
            ->where('staff_id', $staffId)
            ->when($exceptSectionId, fn ($q) => $q->where('section_id', '!=', $exceptSectionId))
            ->exists();
    }
}
