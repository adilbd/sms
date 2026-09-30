<?php

namespace App\Repositories\Eloquent;

use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Repositories\Contracts\StudentEnrolmentRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class StudentEnrolmentRepository implements StudentEnrolmentRepositoryInterface
{
    public function lockSection(int $sectionId): Section
    {
        $section = Section::query()->whereKey($sectionId)->lockForUpdate()->firstOrFail();

        return $section->load(['class', 'shift']);
    }

    public function forStudentAndYear(Student $student, int $academicYearId): ?StudentEnrolment
    {
        return StudentEnrolment::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', $academicYearId)
            ->first();
    }

    public function rollNumberTaken(int $sectionId, int $academicYearId, int $rollNumber, ?int $exceptId): bool
    {
        return StudentEnrolment::query()
            ->where('section_id', $sectionId)
            ->where('academic_year_id', $academicYearId)
            ->where('roll_number', $rollNumber)
            ->when($exceptId, fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->exists();
    }

    public function countActiveInSection(int $sectionId, int $academicYearId): int
    {
        return StudentEnrolment::query()
            ->where('section_id', $sectionId)
            ->where('academic_year_id', $academicYearId)
            ->where('status', StudentEnrolment::STATUS_ACTIVE)
            ->count();
    }

    public function create(array $attributes): StudentEnrolment
    {
        return StudentEnrolment::create($attributes)->refresh();
    }

    public function update(StudentEnrolment $enrolment, array $attributes): StudentEnrolment
    {
        $enrolment->update($attributes);

        return $enrolment;
    }

    public function historyFor(Student $student): Collection
    {
        return $student->enrolments()
            ->with(['academicYear', 'class', 'section', 'optionalSubject'])
            ->newestYearFirst()
            ->get();
    }
}
