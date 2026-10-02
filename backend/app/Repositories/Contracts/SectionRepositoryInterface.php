<?php

namespace App\Repositories\Contracts;

use App\Models\Section;

interface SectionRepositoryInterface extends RepositoryInterface
{
    /**
     * Whether any student is enrolled in the section (student_enrolments).
     */
    public function hasStudents(Section $section): bool;

    public function hasAttendances(Section $section): bool;

    public function hasSubjectAssignments(Section $section): bool;

    /** Whether the section has a class routine. */
    public function hasRoutineSlots(Section $section): bool;

    /** Whether the section has homework. */
    public function hasHomework(Section $section): bool;
}
