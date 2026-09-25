<?php

namespace App\Repositories\Contracts;

use App\Models\Subject;

interface SubjectRepositoryInterface extends RepositoryInterface
{
    public function isUsedInExamSchedules(Subject $subject): bool;
}
