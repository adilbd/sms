<?php

namespace App\Repositories\Contracts;

use App\Models\AcademicYear;
use App\Models\Period;
use App\Models\RoutineSlot;
use App\Models\Section;
use Illuminate\Database\Eloquent\Collection;

/**
 * The cells of the weekly class routine. Not a CRUD repository: a section's grid is read
 * and replaced as a whole, so this contract is narrower than the base RepositoryInterface
 * (like ClassTeacherRepositoryInterface). Time arguments are `H:i:s` strings.
 */
interface RoutineRepositoryInterface
{
    /**
     * Takes a row lock on the section (`select ... for update`) and returns the fresh row
     * with its class and shift. Call inside a transaction; a no-op lock on SQLite.
     */
    public function lockSection(int $sectionId): Section;

    /**
     * Locks the academic year row, so concurrent routine saves for different sections
     * can't both pass a teacher or room clash check against each other's old state. Always
     * taken after lockSection(). Call inside a transaction.
     */
    public function lockAcademicYear(int $academicYearId): AcademicYear;

    /**
     * Every slot of the section in the year with its period and subject and teacher, in
     * period order.
     *
     * @return Collection<int, RoutineSlot>
     */
    public function forSectionAndYear(int $sectionId, int $academicYearId): Collection;

    /**
     * Every slot the staff member teaches in the year, with its period, subject and section
     * (and the section's class and shift).
     *
     * @return Collection<int, RoutineSlot>
     */
    public function forStaffAndYear(int $staffId, int $academicYearId): Collection;

    /**
     * Every slot that uses $period, with its section.
     *
     * @return Collection<int, RoutineSlot>
     */
    public function usingPeriod(Period $period): Collection;

    /**
     * A slot of ANOTHER section that has the same teacher on the same day in a period whose
     * time overlaps $start-$end, or null. Compares real time ranges, so periods of different
     * shifts clash when their times overlap. Loaded with its section, class and period.
     */
    public function findTeacherClash(int $academicYearId, int $exceptSectionId, int $staffId, string $day, string $start, string $end, ?int $exceptPeriodId = null): ?RoutineSlot;

    /**
     * Like findTeacherClash(), for a room (`$roomKey` is the normalized room name).
     */
    public function findRoomClash(int $academicYearId, int $exceptSectionId, string $roomKey, string $day, string $start, string $end, ?int $exceptPeriodId = null): ?RoutineSlot;

    /**
     * Makes the section's slots for the year exactly $rows (`day`, `period_id`, `subject_id`,
     * `staff_id`, `room`, `room_key`): every old slot is deleted and the rows inserted.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function replaceForSection(Section $section, int $academicYearId, array $rows): void;

    /**
     * Sets `staff_id` to null on the section's slots for the year whose teacher no longer
     * holds a subject assignment for (section, slot's subject, year). The subject and room
     * stay. Returns the number of slots cleared.
     */
    public function clearUnassignedTeachers(int $sectionId, int $academicYearId): int;
}
