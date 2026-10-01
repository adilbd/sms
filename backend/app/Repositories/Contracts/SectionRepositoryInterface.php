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

    public function hasExamSchedules(Section $section): bool;

    public function hasSubjectAssignments(Section $section): bool;
}
