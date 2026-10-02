<?php

namespace App\Repositories\Contracts;

use App\Models\Section;
use Illuminate\Database\Eloquent\Collection;

/**
 * A narrower contract than RepositoryInterface: class_sections has no standalone CRUD
 * endpoint (see App\Services\ClassTeacherService, used only through
 * SectionController's class-teacher actions), so there's no need for find/paginate.
 */
interface ClassTeacherRepositoryInterface
{
    /**
     * Every class-teacher row for $section, most recent year first, the main teacher first.
     */
    public function forSection(Section $section): Collection;

    /**
     * The class-teacher rows (main or co-teacher) where staff member $staffId leads a section in
     * $academicYearId, with the section (and its class and shift).
     */
    public function forStaffAndYear(int $staffId, int $academicYearId): Collection;

    /**
     * The class-teacher rows of $section in $academicYearId, with staff, the main teacher first.
     */
    public function forSectionAndYear(Section $section, int $academicYearId): Collection;

    /**
     * Takes a row lock on $section (`select ... for update`) so concurrent class-teacher
     * writes for it run one after another. Call inside a transaction. Returns the fresh row.
     */
    public function lockSection(Section $section): Section;

    /**
     * Makes the section's rows for the year exactly $teachers: rows for other staff are
     * deleted, existing ones get the new `is_main` and the rest are created, with class_id
     * filled from the section. An empty list removes every row.
     *
     * @param  list<array{staff_id: int, is_main: bool}>  $teachers
     */
    public function replaceForSectionAndYear(Section $section, int $academicYearId, array $teachers): void;

    /**
     * Removes every class-teacher row for $section, e.g. as part of
     * SectionService::delete()'s transaction.
     */
    public function deleteForSection(Section $section): void;

    /**
     * Whether any class-teacher row of $section points at a staff member who doesn't
     * belong to shift $shiftId.
     */
    public function hasTeacherOutsideShift(Section $section, int $shiftId): bool;
}
