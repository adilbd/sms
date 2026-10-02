<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\RoutineSlot;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\PeriodRepositoryInterface;
use App\Repositories\Contracts\RoutineRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Repositories\Contracts\SubjectAssignmentRepositoryInterface;
use App\Support\UniqueViolation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The weekly class routine: a day x period grid per section and academic year, each cell a
 * subject, usually a teacher and a room. Saving replaces the section's whole grid in one
 * transaction with the section (and then the academic year) locked, so concurrent saves
 * can't both pass a clash check. Every rule is checked before anything is written, with
 * errors keyed per cell (`slots.2.staff_id`).
 *
 * Reads (section, teacher, a student's section) return the same shapes for the admin API,
 * `/api/my/routine` and the portal, so they always agree.
 *
 * Read shapes: a section routine is `{academic_year, section, days, periods, slots}`; a
 * teacher routine is `{academic_year, staff, days, slots}`. `days` are the school days (the
 * week minus the institute's weekly holidays).
 */
class RoutineService
{
    public function __construct(
        private RoutineRepositoryInterface $routines,
        private PeriodRepositoryInterface $periods,
        private SubjectAssignmentRepositoryInterface $assignments,
        private AcademicYearRepositoryInterface $years,
        private StaffRepositoryInterface $staff,
        private InstituteSettingsService $institute,
        private TeacherScope $teacherScope,
    ) {}

    /**
     * A section's routine for the year (default: the active one). A teacher may only read a
     * section they teach in or lead (403 otherwise); anyone else with the permission may
     * read any.
     *
     * @return array{academic_year: ?AcademicYear, section: Section, days: list<string>, periods: Collection, slots: Collection}
     */
    public function forSection(Section $section, ?int $academicYearId = null, ?User $viewer = null): array
    {
        $year = $this->resolveYear($academicYearId);

        if ($viewer !== null) {
            $allowed = $this->teacherScope->sectionIdsFor($viewer, $year?->id);

            abort_if($allowed !== null && ! in_array($section->id, $allowed, true), 403, 'You do not teach or lead this section.');
        }

        return $this->sectionRoutine($section, $year);
    }

    /**
     * A student's own section routine for the year of their current enrolment. A student
     * with no enrolment gets an empty routine.
     *
     * @return array{academic_year: ?AcademicYear, section: ?Section, days: list<string>, periods: Collection, slots: Collection}
     */
    public function forStudent(Student $student): array
    {
        $enrolment = $student->currentEnrolment;

        if ($enrolment === null) {
            return [
                'academic_year' => $this->years->findActive(),
                'section' => null,
                'days' => $this->schoolDays(),
                'periods' => new Collection,
                'slots' => new Collection,
            ];
        }

        $section = $enrolment->section->loadMissing(['class', 'shift']);

        return $this->sectionRoutine($section, $this->years->findOrFail((int) $enrolment->academic_year_id));
    }

    /**
     * One teacher's week. A teacher may only read their own (403 for anyone else's); an
     * admin or any other non-teacher reader may read anyone's.
     *
     * @return array{academic_year: ?AcademicYear, staff: ?Staff, days: list<string>, slots: Collection}
     */
    public function forTeacher(Staff $staff, ?int $academicYearId = null, ?User $viewer = null): array
    {
        if ($viewer !== null && $this->teacherScope->isScoped($viewer)) {
            $mine = $this->staff->findByUserId($viewer->id);

            abort_unless($mine !== null && $mine->id === $staff->id, 403, 'You can only see your own routine.');
        }

        return $this->teacherRoutine($staff, $this->resolveYear($academicYearId));
    }

    /**
     * The signed-in teacher's own week. A teacher with no linked staff row gets an empty one.
     *
     * @return array{academic_year: ?AcademicYear, staff: ?Staff, days: list<string>, slots: Collection}
     */
    public function forTeacherUser(User $user, ?int $academicYearId = null): array
    {
        $staff = $this->staff->findByUserId($user->id);
        $year = $this->resolveYear($academicYearId);

        return $staff === null
            ? ['academic_year' => $year, 'staff' => null, 'days' => $this->schoolDays(), 'slots' => new Collection]
            : $this->teacherRoutine($staff, $year);
    }

    /**
     * Replaces the section's whole grid for the year: cells left out are removed. Every
     * rule is checked first (the period is one of the section's shift and not a break; the
     * day is not a weekly holiday; the subject is in the section's curriculum; the teacher
     * is active and assigned to the subject in this section and year; the teacher and the
     * room are free in every other section at overlapping times), reporting every problem
     * keyed per cell.
     *
     * @param  array{academic_year_id: int, slots: list<array{day: string, period_id: int, subject_id: int, staff_id?: int|null, room?: string|null}>}  $data
     * @return array{academic_year: ?AcademicYear, section: Section, days: list<string>, periods: Collection, slots: Collection}
     */
    public function replaceForSection(Section $section, array $data): array
    {
        $year = DB::transaction(function () use ($section, $data) {
            $locked = $this->routines->lockSection($section->id);
            $year = $this->routines->lockAcademicYear((int) $data['academic_year_id']);

            $rows = $this->validatedRows($locked, $year, array_values($data['slots']));

            try {
                $this->routines->replaceForSection($locked, $year->id, $rows);
            } catch (UniqueConstraintViolationException $e) {
                if (UniqueViolation::is($e, 'routine_slots', ['academic_year_id', 'section_id', 'day', 'period_id'], 'routine_slots_year_section_day_period_unique')) {
                    throw ValidationException::withMessages(['slots' => ['A period is listed twice for the same day.']]);
                }

                throw $e;
            }

            return $year;
        });

        return $this->sectionRoutine($section->loadMissing(['class', 'shift']), $year);
    }

    /**
     * The week's school days: every day except the institute's weekly holidays.
     *
     * @return list<string>
     */
    public function schoolDays(): array
    {
        return array_values(array_diff(RoutineSlot::DAYS, $this->institute->weeklyHolidays()));
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     * @return list<array<string, mixed>>
     */
    private function validatedRows(Section $section, AcademicYear $year, array $slots): array
    {
        $periods = $this->periods->forShift((int) $section->shift_id)->keyBy('id');
        $curriculum = $this->assignments->curriculumSubjectIds((int) $section->class_id, $section->group);
        $holidays = $this->institute->weeklyHolidays();
        $staffCache = [];
        $seen = [];
        $errors = [];
        $rows = [];

        foreach ($slots as $i => $slot) {
            $period = $periods->get((int) $slot['period_id']);
            $day = (string) $slot['day'];
            $subjectId = (int) $slot['subject_id'];
            $staffId = filled($slot['staff_id'] ?? null) ? (int) $slot['staff_id'] : null;
            $room = $this->cleanRoom($slot['room'] ?? null);
            $cellIsValid = true;

            if ($period === null) {
                $errors["slots.{$i}.period_id"][] = "This period does not belong to the section's shift.";
                $cellIsValid = false;
            } elseif ($period->is_break) {
                $errors["slots.{$i}.period_id"][] = 'A break period cannot hold a class.';
                $cellIsValid = false;
            }

            if (in_array($day, $holidays, true)) {
                $errors["slots.{$i}.day"][] = ucfirst($day).' is a weekly holiday.';
                $cellIsValid = false;
            }

            if ($period !== null && isset($seen["{$day}|{$period->id}"])) {
                $errors["slots.{$i}.period_id"][] = 'This period is listed more than once for the day.';
                $cellIsValid = false;
            }

            if ($period !== null) {
                $seen["{$day}|{$period->id}"] = true;
            }

            if (! in_array($subjectId, $curriculum, true)) {
                $errors["slots.{$i}.subject_id"][] = $section->group === null
                    ? "The subject is not in this section's class curriculum."
                    : "The subject is not in the curriculum of this section's class for its group.";
            }

            $teacher = null;

            if ($staffId !== null) {
                $teacher = $staffCache[$staffId] ??= $this->staff->find($staffId);

                if ($teacher === null || $teacher->status !== Staff::STATUS_ACTIVE) {
                    $errors["slots.{$i}.staff_id"][] = 'The teacher must be an active staff member.';
                    $teacher = null;
                } elseif ($this->assignments->findFor($section->id, $subjectId, $year->id, $staffId) === null) {
                    $errors["slots.{$i}.staff_id"][] = 'This teacher is not assigned to this subject in this section for the academic year.';
                    $teacher = null;
                }
            }

            // Clashes compare real time ranges against the other sections, so only a cell
            // whose day and period are sound can be checked.
            if ($cellIsValid && $period !== null) {
                if ($teacher !== null && $clash = $this->routines->findTeacherClash($year->id, $section->id, $teacher->id, $day, $period->start_time, $period->end_time)) {
                    $errors["slots.{$i}.staff_id"][] = sprintf(
                        '%s is already teaching %s on %s at %s.',
                        $teacher->name_en ?: $teacher->name_bn,
                        $this->describe($clash),
                        ucfirst($day),
                        $this->range($clash),
                    );
                }

                if ($room !== null && $clash = $this->routines->findRoomClash($year->id, $section->id, $this->roomKey($room), $day, $period->start_time, $period->end_time)) {
                    $errors["slots.{$i}.room"][] = sprintf(
                        'Room "%s" is already used by %s on %s at %s.',
                        $room,
                        $this->describe($clash),
                        ucfirst($day),
                        $this->range($clash),
                    );
                }
            }

            $rows[] = [
                'day' => $day,
                'period_id' => (int) $slot['period_id'],
                'subject_id' => $subjectId,
                'staff_id' => $staffId,
                'room' => $room,
                'room_key' => $room === null ? null : $this->roomKey($room),
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }

    /**
     * @return array{academic_year: ?AcademicYear, section: Section, days: list<string>, periods: Collection, slots: Collection}
     */
    private function sectionRoutine(Section $section, ?AcademicYear $year): array
    {
        return [
            'academic_year' => $year,
            'section' => $section->loadMissing(['class', 'shift']),
            'days' => $this->schoolDays(),
            'periods' => $this->periods->forShift((int) $section->shift_id),
            'slots' => $year ? $this->routines->forSectionAndYear($section->id, $year->id) : new Collection,
        ];
    }

    /**
     * @return array{academic_year: ?AcademicYear, staff: Staff, days: list<string>, slots: Collection}
     */
    private function teacherRoutine(Staff $staff, ?AcademicYear $year): array
    {
        return [
            'academic_year' => $year,
            'staff' => $staff,
            'days' => $this->schoolDays(),
            'slots' => $year ? $this->routines->forStaffAndYear($staff->id, $year->id) : new Collection,
        ];
    }

    private function resolveYear(?int $academicYearId): ?AcademicYear
    {
        return $academicYearId !== null ? $this->years->findOrFail($academicYearId) : $this->years->findActive();
    }

    /**
     * The room as typed, trimmed with inner whitespace collapsed; null when blank.
     */
    private function cleanRoom(mixed $room): ?string
    {
        if (! is_string($room)) {
            return null;
        }

        $room = trim((string) preg_replace('/\s+/u', ' ', $room));

        return $room === '' ? null : $room;
    }

    /**
     * Rooms are compared case-insensitively.
     */
    private function roomKey(string $room): string
    {
        return mb_strtolower($room);
    }

    private function describe(RoutineSlot $slot): string
    {
        return trim(($slot->section?->class?->name ?? '').' '.($slot->section?->name ?? ''));
    }

    private function range(RoutineSlot $slot): string
    {
        return substr((string) $slot->period?->start_time, 0, 5).'-'.substr((string) $slot->period?->end_time, 0, 5);
    }
}
