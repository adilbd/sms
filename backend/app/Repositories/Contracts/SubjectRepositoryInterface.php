<?php

namespace App\Repositories\Contracts;

use App\Models\Subject;

interface SubjectRepositoryInterface extends RepositoryInterface
{
    public function isUsedInExamSchedules(Subject $subject): bool;

    public function hasTeacherAssignments(Subject $subject): bool;

    public function isUsedInCurriculum(Subject $subject): bool;

    /**
     * Whether any student enrolment uses the subject as its 4th (optional) subject.
     */
    public function isUsedAsOptionalSubject(Subject $subject): bool;
}
