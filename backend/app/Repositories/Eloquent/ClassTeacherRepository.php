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
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->get();
    }

    public function forStaffAndYear(int $staffId, int $academicYearId): Collection
    {
        return ClassSection::where('staff_id', $staffId)
            ->where('academic_year_id', $academicYearId)
            ->with(['section.class', 'section.shift'])
            ->orderBy('section_id')
            ->get();
    }

    public function forSectionAndYear(Section $section, int $academicYearId): Collection
    {
        return ClassSection::where('section_id', $section->id)
            ->where('academic_year_id', $academicYearId)
            ->with(['staff', 'academicYear'])
            ->orderByDesc('is_main')
            ->orderBy('id')
            ->get();
    }

    public function lockSection(Section $section): Section
    {
        return Section::query()->whereKey($section->getKey())->lockForUpdate()->firstOrFail();
    }

    public function replaceForSectionAndYear(Section $section, int $academicYearId, array $teachers): void
    {
        ClassSection::where('section_id', $section->id)
            ->where('academic_year_id', $academicYearId)
            ->whereNotIn('staff_id', array_column($teachers, 'staff_id'))
            ->delete();

        foreach ($teachers as $row) {
            ClassSection::updateOrCreate(
                ['section_id' => $section->id, 'academic_year_id' => $academicYearId, 'staff_id' => $row['staff_id']],
                ['class_id' => $section->class_id, 'is_main' => $row['is_main']],
            );
        }
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
}
