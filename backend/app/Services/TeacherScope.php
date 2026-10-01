<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\ClassTeacherRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Repositories\Contracts\SubjectAssignmentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Limits a signed-in teacher to their own work. Permissions are role-wide (a teacher can
 * `view-students`), so record-scoped rules live here: controllers ask for the teacher's
 * section ids / (class, subject) pairs and pass them on as internal filters, never from
 * request input. An admin (or any non-teacher) is not scoped: the methods return null.
 */
class TeacherScope
{
    public function __construct(
        private UserRepositoryInterface $users,
        private StaffRepositoryInterface $staff,
        private SubjectAssignmentRepositoryInterface $assignments,
        private ClassTeacherRepositoryInterface $classTeachers,
        private AcademicYearRepositoryInterface $years,
    ) {}

    /**
     * Whether the user is a teacher who has to be limited to their own work: holds the
     * teacher role and isn't also an admin.
     */
    public function isScoped(User $user): bool
    {
        return $this->users->hasRole($user, 'teacher') && ! $this->users->hasRole($user, 'admin');
    }

    /**
     * The teacher's staff row with their assignments and class-teacher sections for the
     * year (default: the active one), or null when no staff member is linked to the user.
     * Without any academic year the lists are empty.
     */
    public function forUser(User $user, ?int $academicYearId = null): ?TeacherContext
    {
        $staff = $this->staff->findByUserId($user->id);

        if ($staff === null) {
            return null;
        }

        $year = $academicYearId !== null ? $this->years->findOrFail($academicYearId) : $this->years->findActive();

        return new TeacherContext(
            $staff,
            $year,
            $year ? $this->assignments->forStaffAndYear($staff->id, $year->id) : new Collection,
            $year ? $this->classTeachers->forStaffAndYear($staff->id, $year->id) : new Collection,
        );
    }

    /**
     * The sections a scoped teacher teaches or leads (empty when they have no staff link
     * or the year has no assignments), or null when the user isn't scoped.
     *
     * @return list<int>|null
     */
    public function sectionIdsFor(User $user, ?int $academicYearId = null): ?array
    {
        if (! $this->isScoped($user)) {
            return null;
        }

        return $this->forUser($user, $academicYearId)?->sectionIds() ?? [];
    }

    /**
     * The (class, subject) pairs a scoped teacher is assigned in the year, or null when
     * the user isn't scoped.
     *
     * @return list<array{class_id: int, subject_id: int}>|null
     */
    public function classSubjectPairsFor(User $user, ?int $academicYearId = null): ?array
    {
        if (! $this->isScoped($user)) {
            return null;
        }

        return $this->forUser($user, $academicYearId)?->classSubjectPairs() ?? [];
    }
}
