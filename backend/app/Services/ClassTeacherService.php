<?php

namespace App\Services;

use App\Models\ClassSection;
use App\Models\Section;
use App\Models\Staff;
use App\Repositories\Contracts\ClassTeacherRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assigns/unassigns a section's class teacher (staff) per academic year. Managed
 * through SectionController's class-teacher actions, not a standalone CRUD endpoint.
 */
class ClassTeacherService
{
    public function __construct(
        private ClassTeacherRepositoryInterface $classTeachers,
        private StaffRepositoryInterface $staff,
    ) {}

    /**
     * One row per academic year the section has ever had a class teacher assigned in.
     */
    public function listForSection(Section $section): Collection
    {
        return $this->classTeachers->forSection($section);
    }

    /**
     * A plain object, not an array: ClassTeacherResource reads section_id/
     * academic_year_id/staff off it the same way it reads them off a real
     * App\Models\ClassSection (JsonResource's magic __get only proxies object
     * property access, not array access — see DelegatesToResource::__get()).
     *
     * @param  array{academic_year_id: int, staff_id?: int|null}  $data
     */
    public function assign(Section $section, array $data): object
    {
        $academicYearId = (int) $data['academic_year_id'];
        $staffId = $data['staff_id'] ?? null;

        return DB::transaction(function () use ($section, $academicYearId, $staffId) {
            if ($staffId === null) {
                // A no-op when nothing was assigned yet, same as deleting a
                // non-existent row.
                $this->classTeachers->deleteForSectionAndYear($section, $academicYearId);

                return (object) ['section_id' => $section->id, 'academic_year_id' => $academicYearId, 'staff' => null];
            }

            $staff = $this->staff->findOrFail((int) $staffId);
            $this->ensureCanLead($staff, $section);
            $this->ensureNotAlreadyLeading($academicYearId, $staff->id, $section->id);

            $classSection = $this->withUniqueAssignment(
                fn () => $this->classTeachers->upsert($section, $academicYearId, $staff->id)
            );

            return (object) ['section_id' => $section->id, 'academic_year_id' => $academicYearId, 'staff' => $classSection->staff];
        });
    }

    private function ensureCanLead(Staff $staff, Section $section): void
    {
        if ($staff->status !== Staff::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'staff_id' => ['The staff member must be active to lead a section.'],
            ]);
        }

        if ($staff->category !== Staff::CATEGORY_TEACHER) {
            throw ValidationException::withMessages([
                'staff_id' => ['The staff member must be a teacher to lead a section.'],
            ]);
        }

        if (! $this->staff->belongsToShift($staff, $section->shift_id)) {
            throw ValidationException::withMessages([
                'staff_id' => ["The staff member must belong to the section's shift."],
            ]);
        }
    }

    private function ensureNotAlreadyLeading(int $academicYearId, int $staffId, int $exceptSectionId): void
    {
        if ($this->classTeachers->teacherLeadsAnotherSection($academicYearId, $staffId, $exceptSectionId)) {
            throw ValidationException::withMessages([
                'staff_id' => ['This teacher already leads another section this academic year.'],
            ]);
        }
    }

    /**
     * The uniqueness rule runs before the write, so a concurrent request can still hit
     * the database index (see SubjectService::withUniqueCode()).
     */
    private function withUniqueAssignment(callable $write): ClassSection
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            // Two unique keys can trip: (section, year) and (year, staff).
            throw ValidationException::withMessages([
                'staff_id' => ['This section or teacher already has a class-teacher assignment for this academic year.'],
            ]);
        }
    }
}
