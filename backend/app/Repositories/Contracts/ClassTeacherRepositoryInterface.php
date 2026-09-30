<?php

namespace App\Repositories\Contracts;

use App\Models\ClassSection;
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
     * Every class-teacher row for $section, one per academic year, most recent first.
     */
    public function forSection(Section $section): Collection;

    /**
     * Creates or updates the (section, academic year) row with $staffId, filling
     * class_id from the section.
     */
    public function upsert(Section $section, int $academicYearId, int $staffId): ClassSection;

    /**
     * A no-op when no row exists for that (section, academic year), matching
     * "unassigning when nothing is assigned" being a no-op.
     */
    public function deleteForSectionAndYear(Section $section, int $academicYearId): void;

    /**
     * Removes every class-teacher row for $section, e.g. as part of
     * SectionService::delete()'s transaction.
     */
    public function deleteForSection(Section $section): void;

    /**
     * Whether $staffId already leads a section other than $exceptSectionId in
     * $academicYearId (a teacher leads at most one section per year).
     */
    public function teacherLeadsAnotherSection(int $academicYearId, int $staffId, ?int $exceptSectionId): bool;
}
