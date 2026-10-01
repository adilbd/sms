<?php

namespace App\Repositories\Contracts;

use App\Models\Section;
use App\Models\SubjectAssignment;
use Illuminate\Database\Eloquent\Collection;

interface SubjectAssignmentRepositoryInterface extends RepositoryInterface
{
    /**
     * Takes a row lock on $section (`select ... for update`), so concurrent assignment
     * writes for the same section run one after another. Call inside a transaction.
     * Returns the freshly read row.
     */
    public function lockSection(Section $section): Section;

    /**
     * The ids of every subject in the curriculum of class $classId (any group, any type).
     *
     * @return list<int>
     */
    public function curriculumSubjectIds(int $classId): array;

    /**
     * The assignment of $subjectId in $sectionId for $academicYearId, or null.
     */
    public function findFor(int $sectionId, int $subjectId, int $academicYearId): ?SubjectAssignment;

    /**
     * Every assignment of $section in $academicYearId, with relations, in subject order.
     */
    public function forSectionAndYear(Section $section, int $academicYearId): Collection;

    /**
     * Makes $section's assignments for the year exactly $subjectStaff (subject_id => staff_id):
     * assignments of other subjects are deleted, existing ones get the new teacher and the
     * rest are created, with class_id filled from the section.
     *
     * @param  array<int, int>  $subjectStaff
     */
    public function replaceForSection(Section $section, int $academicYearId, array $subjectStaff): void;

    /**
     * Whether the staff member linked to user $userId (`staff.user_id`) holds the
     * assignment of $subjectId in $sectionId for $academicYearId.
     */
    public function userHoldsAssignment(int $userId, int $sectionId, int $subjectId, int $academicYearId): bool;
}
