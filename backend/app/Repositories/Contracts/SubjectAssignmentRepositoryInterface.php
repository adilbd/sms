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
     * The assignment of staff member $staffId to $subjectId in $sectionId for
     * $academicYearId, or null.
     */
    public function findFor(int $sectionId, int $subjectId, int $academicYearId, int $staffId): ?SubjectAssignment;

    /**
     * Every assignment of $section in $academicYearId, with relations, in subject order.
     */
    public function forSectionAndYear(Section $section, int $academicYearId): Collection;

    /**
     * Every assignment held by staff member $staffId in $academicYearId, with its
     * subject, class and section (and their shift), in section then subject order.
     */
    public function forStaffAndYear(int $staffId, int $academicYearId): Collection;

    /**
     * Makes $section's assignments for the year exactly $subjectStaff (subject_id => staff
     * ids): assignments of other subjects are deleted, a listed subject keeps the rows of its
     * staff, loses the others and gains the missing ones (an empty list removes the subject),
     * with class_id filled from the section.
     *
     * @param  array<int, list<int>>  $subjectStaff
     */
    public function replaceForSection(Section $section, int $academicYearId, array $subjectStaff): void;

    /**
     * Whether the staff member linked to user $userId (`staff.user_id`) holds an
     * assignment of $subjectId in $sectionId for $academicYearId and is still active
     * (`staff.status = active`).
     */
    public function userHoldsAssignment(int $userId, int $sectionId, int $subjectId, int $academicYearId): bool;
}
