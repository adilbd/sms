<?php

namespace App\Repositories\Contracts;

use App\Models\Classes;
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
     * Takes a row lock on class $classId. Assignment writes lock the class first and the
     * section second (see lockSection()), always in that order, so they queue behind a
     * curriculum replacement and can't deadlock with each other. Call inside a transaction.
     */
    public function lockClass(int $classId): Classes;

    /**
     * The ids of the subjects in the curriculum of class $classId (any type). With a
     * $group, only the rows that apply to it (the group's own and the class-wide ones),
     * which is what a section with that group accepts; without one, every row.
     *
     * @return list<int>
     */
    public function curriculumSubjectIds(int $classId, ?string $group = null): array;

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
     * assignment of $subjectId in $sectionId for $academicYearId and is still active
     * (`staff.status = active`).
     */
    public function userHoldsAssignment(int $userId, int $sectionId, int $subjectId, int $academicYearId): bool;
}
