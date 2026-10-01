<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\AttendanceRepositoryInterface;
use App\Repositories\Contracts\HolidayRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Repositories\Contracts\StudentEnrolmentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\SchoolDays;
use App\Support\UniqueViolation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Daily attendance, once a day per section. The section's class teacher for the date's
 * academic year, or an admin, reads and saves the whole sheet; everyone else gets 403.
 * "Today" and every date are Asia/Dhaka calendar dates (`Y-m-d` strings). A school day is
 * any date that is neither a weekly holiday (the `weekly_holidays` institute setting,
 * Friday by default) nor a listed holiday. Saving takes the section lock and upserts the
 * sheet in one transaction, like ExamMarkService; students left out of it are untouched.
 *
 * The same reports serve the staff endpoints and the student/guardian `/api/my/*` ones, so
 * both always agree. See docs/tasks/attendance.md.
 */
class AttendanceService
{
    /** A teacher may change attendance of today and the six days before it. */
    public const TEACHER_EDIT_WINDOW_DAYS = 7;

    private const TIMEZONE = 'Asia/Dhaka';

    public function __construct(
        private AttendanceRepositoryInterface $attendance,
        private HolidayRepositoryInterface $holidays,
        private StudentEnrolmentRepositoryInterface $enrolments,
        private AcademicYearRepositoryInterface $years,
        private SectionRepositoryInterface $sections,
        private UserRepositoryInterface $users,
        private TeacherScope $teacherScope,
        private InstituteSettingsService $institute,
        private StudentService $students,
    ) {}

    /**
     * Today's date in Asia/Dhaka, the default for every date and month.
     */
    public function today(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->toDateString();
    }

    /**
     * The sheet for one section and date (default: today).
     *
     * @return array{section: Section, academic_year: AcademicYear, date: string, holiday: ?array, enrolments: Collection}
     */
    public function sheet(User $user, int $sectionId, ?string $date): array
    {
        $date ??= $this->today();
        $section = $this->sections->findOrFail($sectionId);
        $year = $this->ensureInYear($this->accessibleYear($user, $section, $date), $date, 'date');

        return $this->buildSheet($section, $year, $date);
    }

    /**
     * Saves the whole sheet and returns it as saved. Errors are keyed per row
     * (`entries.3.student_id`). 403 unless the user is the section's class teacher or an
     * admin.
     *
     * @param  array{section_id: int, date?: string|null, entries: list<array{student_id: int, status: string, remarks?: ?string}>}  $data
     * @return array{section: Section, academic_year: AcademicYear, date: string, holiday: ?array, enrolments: Collection}
     */
    public function save(User $user, array $data): array
    {
        $date = $data['date'] ?? $this->today();
        $section = $this->sections->findOrFail((int) $data['section_id']);
        $year = $this->ensureInYear($this->accessibleYear($user, $section, $date), $date, 'date');

        $this->ensureCanMark($user, $date);

        try {
            DB::transaction(function () use ($user, $section, $year, $date, $data) {
                $locked = $this->enrolments->lockSection($section->id);

                // Checked again now the lock is held: the class teacher may have changed.
                $this->authorizeSection($user, $locked, $year);

                $onSheet = $this->onRoll($this->attendance->candidateEnrolments($locked->id, $year->id, $date), $date)->keyBy('student_id');
                $rows = $this->validatedRows($onSheet, array_values($data['entries']));

                $this->attendance->saveRows($rows, $locked->id, $year->id, $date, $user->id);
            });
        } catch (UniqueConstraintViolationException $e) {
            // A concurrent save of the same day (the section lock should prevent it).
            if (UniqueViolation::is($e, 'attendances', ['enrolment_id', 'date'])) {
                throw ValidationException::withMessages(['date' => ['This day was just saved by someone else. Reload and try again.']]);
            }

            throw $e;
        }

        return $this->buildSheet($section, $year, $date);
    }

    /**
     * The monthly grid of one section: every student with a day-by-day status, totals and
     * a percentage. A student who left mid-month appears with only the days recorded.
     *
     * @return array{month: string, section: Section, school_days: list<string>, students: list<array<string, mixed>>}
     */
    public function report(User $user, int $sectionId, ?string $month): array
    {
        [$month, $first, $last] = $this->monthRange($month);
        $section = $this->sections->findOrFail($sectionId);
        $year = $this->accessibleYear($user, $section, $first)
            ?? throw ValidationException::withMessages(['month' => ['There is no academic year for this month.']]);

        $schoolDays = $this->schoolDays($year, $first, $last);

        $students = $this->attendance->reportEnrolments($section->id, $year->id, $first, $last)
            // A student who joined after the month has no school days in it (unless a record exists).
            ->filter(fn ($enrolment) => $this->enrolledOn($enrolment) <= $last || $enrolment->attendances->isNotEmpty())
            ->map(fn ($enrolment) => [
                'student' => $this->studentSummary($enrolment->student, $enrolment->roll_number),
                ...$this->summarise($this->sinceEnrolment($schoolDays, $enrolment), $enrolment->attendances, $enrolment->status === StudentEnrolment::STATUS_ACTIVE),
            ])
            ->values()
            ->all();

        return ['month' => $month, 'section' => $section, 'school_days' => $schoolDays, 'students' => $students];
    }

    /**
     * One student's month and year-to-date totals, for staff. 403 unless the user is an
     * admin or class teacher of the student's section that year.
     *
     * @return array<string, mixed>
     */
    public function studentMonth(User $user, Student $student, ?string $month): array
    {
        return $this->studentView($student, $month, $user);
    }

    /**
     * The signed-in student's own month.
     *
     * @return array<string, mixed>
     */
    public function ownMonth(User $user, ?string $month): array
    {
        return $this->studentView($this->students->findOwn($user), $month, null);
    }

    /**
     * A child's month for their guardian. 403 unless the student is the guardian's own
     * child.
     *
     * @return array<string, mixed>
     */
    public function childMonth(User $guardian, int $studentId, ?string $month): array
    {
        return $this->studentView($this->students->findChildOf($guardian, $studentId), $month, null);
    }

    /**
     * @param  User|null  $staff  the staff user to authorize against the student's section;
     *                            null for a student or guardian reading their own record
     * @return array<string, mixed>
     */
    private function studentView(Student $student, ?string $month, ?User $staff): array
    {
        [$month, $first, $last] = $this->monthRange($month);
        $year = $this->yearForMonth($first);
        $enrolment = $this->enrolments->forStudentAndYear($student, $year->id);

        abort_if($enrolment === null, 404, 'The student has no enrolment in this academic year.');

        if ($staff !== null) {
            $this->authorizeSection($staff, $this->sections->findOrFail((int) $enrolment->section_id), $year);
        }

        $active = $enrolment->status === StudentEnrolment::STATUS_ACTIVE;
        $yearStart = $year->start_date->toDateString();

        // Year to date runs to today, or to the end of the requested month when that is
        // earlier, so a past month shows the figure as it stood then.
        $yearToDate = $this->sinceEnrolment($this->schoolDays($year, $yearStart, $last), $enrolment);
        $records = $this->attendance->recordsForStudent($student->id, $yearStart, $year->end_date->toDateString());

        $monthDays = array_values(array_filter($yearToDate, fn (string $d) => $d >= $first));
        $monthRecords = $records->filter(fn ($r) => $r->date >= $first && $r->date <= $last);
        $ytd = $this->summarise($yearToDate, $records, $active);

        return [
            'month' => $month,
            'student' => $this->studentSummary($student, $enrolment->roll_number),
            'school_days' => $monthDays,
            ...$this->summarise($monthDays, $monthRecords, $active),
            'year_to_date' => [
                'school_days' => $active ? count($yearToDate) : count($ytd['days']),
                'totals' => $ytd['totals'],
                'percentage' => $ytd['percentage'],
            ],
        ];
    }

    /**
     * Totals and percentage over the school days, ignoring any record that falls on a
     * day that is no longer a school day (a holiday added after attendance was marked).
     * The percentage is (present + late) over the school days so far; for an enrolment
     * that is no longer active (left, promoted) it is over the days recorded instead, so a
     * student who left mid-month isn't marked down for the days after.
     *
     * @param  list<string>  $schoolDays
     * @param  iterable<Attendance>  $records
     * @return array{days: array<string, string>, totals: array<string, int>, percentage: string}
     */
    private function summarise(array $schoolDays, iterable $records, bool $active): array
    {
        $isSchoolDay = array_flip($schoolDays);
        $days = [];
        $totals = array_fill_keys(Attendance::STATUSES, 0);

        foreach ($records as $record) {
            if (isset($isSchoolDay[$record->date])) {
                $days[$record->date] = $record->status;
                $totals[$record->status]++;
            }
        }

        ksort($days);

        return [
            'days' => $days,
            'totals' => $totals,
            'percentage' => SchoolDays::percentage(
                $totals[Attendance::STATUS_PRESENT] + $totals[Attendance::STATUS_LATE],
                $active ? count($schoolDays) : count($days)
            ),
        ];
    }

    /**
     * @return array{id: int, student_code: string, name_en: ?string, name_bn: ?string, roll_number: ?int}
     */
    private function studentSummary(Student $student, ?int $rollNumber): array
    {
        return [
            'id' => $student->id,
            'student_code' => $student->student_id,
            'name_en' => $student->name_en,
            'name_bn' => $student->name_bn,
            'roll_number' => $rollNumber,
        ];
    }

    /**
     * @return array{section: Section, academic_year: AcademicYear, date: string, holiday: ?array, enrolments: Collection}
     */
    private function buildSheet(Section $section, AcademicYear $year, string $date): array
    {
        return [
            'section' => $section,
            'academic_year' => $year,
            'date' => $date,
            'holiday' => $this->holidayOn($date),
            'enrolments' => $this->onRoll($this->attendance->candidateEnrolments($section->id, $year->id, $date), $date),
        ];
    }

    /**
     * Why the date is not a school day, or null when it is one.
     *
     * @return array{type: string, name_en: ?string, name_bn: ?string}|null
     */
    private function holidayOn(string $date): ?array
    {
        $holiday = $this->holidays->findByDate($date);

        if ($holiday !== null) {
            return ['type' => 'holiday', 'name_en' => $holiday->name_en, 'name_bn' => $holiday->name_bn];
        }

        $weekday = SchoolDays::weekday($date);

        if (in_array($weekday, $this->institute->weeklyHolidays(), true)) {
            return ['type' => 'weekly', 'name_en' => ucfirst($weekday), 'name_bn' => null];
        }

        return null;
    }

    /**
     * 422 on `date` unless the date can be marked: not in the future, not a holiday, and
     * for a teacher within the last seven days. An admin may edit any past date.
     */
    private function ensureCanMark(User $user, string $date): void
    {
        $today = $this->today();

        if ($date > $today) {
            throw ValidationException::withMessages(['date' => ['Attendance cannot be marked for a future date.']]);
        }

        if ($holiday = $this->holidayOn($date)) {
            $reason = $holiday['type'] === 'weekly'
                ? "{$holiday['name_en']} is a weekly holiday"
                : 'This date is a holiday ('.($holiday['name_en'] ?: $holiday['name_bn']).')';

            throw ValidationException::withMessages(['date' => ["{$reason}, so no attendance is taken."]]);
        }

        if (! $this->users->hasRole($user, 'admin')) {
            $oldest = CarbonImmutable::createFromFormat('!Y-m-d', $today, 'UTC')
                ->subDays(self::TEACHER_EDIT_WINDOW_DAYS - 1)
                ->toDateString();

            if ($date < $oldest) {
                throw ValidationException::withMessages(['date' => [
                    'Teachers can only change attendance of the last '.self::TEACHER_EDIT_WINDOW_DAYS.' days. Ask an admin to change this date.',
                ]]);
            }
        }
    }

    /**
     * 403 unless the user is an admin or the (active) class teacher of the section in
     * the academic year.
     */
    private function authorizeSection(User $user, Section $section, AcademicYear $year): void
    {
        if ($this->users->hasRole($user, 'admin')) {
            return;
        }

        $context = $this->teacherScope->forUser($user, $year->id);

        abort_unless(
            $context !== null
                && $context->staff->status === Staff::STATUS_ACTIVE
                && in_array((int) $section->id, $context->leadingSectionIds(), true),
            403,
            "Only the section's class teacher or an admin can use its attendance."
        );
    }

    /**
     * The academic year of the date's calendar year, after the access check, so a user with
     * no access gets 403 even for a date outside any year. Null when there is no such year.
     */
    private function accessibleYear(User $user, Section $section, string $date): ?AcademicYear
    {
        $year = $this->years->findByYear((int) substr($date, 0, 4));

        if ($year === null) {
            // No year to check the class teacher against: use the active one, so a class
            // teacher still gets the date error and anyone else gets 403.
            if (! $this->users->hasRole($user, 'admin')) {
                $active = $this->years->findActive();
                abort_if($active === null, 403, "Only the section's class teacher or an admin can use its attendance.");
                $this->authorizeSection($user, $section, $active);
            }

            return null;
        }

        $this->authorizeSection($user, $section, $year);

        return $year;
    }

    /**
     * The year, with the date inside its start and end dates; 422 on $key otherwise.
     */
    private function ensureInYear(?AcademicYear $year, string $date, string $key): AcademicYear
    {
        if ($year === null || $date < $year->start_date->toDateString() || $date > $year->end_date->toDateString()) {
            throw ValidationException::withMessages([$key => ['The date is outside the academic year.']]);
        }

        return $year;
    }

    /**
     * The enrolments that were on the roll on $date: began on or before it, and not left
     * or graduated before it (the student's leaving date; a left/graduated student with no
     * leaving date is not listed). Promoted and retained enrolments were active until the
     * year ended, so they stay on the roll.
     *
     * @param  Collection<int, StudentEnrolment>  $candidates
     * @return Collection<int, StudentEnrolment>
     */
    private function onRoll(Collection $candidates, string $date): Collection
    {
        return $candidates->filter(function (StudentEnrolment $enrolment) use ($date) {
            if ($this->enrolledOn($enrolment) > $date) {
                return false;
            }

            if (in_array($enrolment->status, [StudentEnrolment::STATUS_LEFT, StudentEnrolment::STATUS_GRADUATED], true)) {
                $left = $enrolment->student?->leaving_date?->toDateString();

                return $left !== null && $left >= $date;
            }

            return true;
        })->values();
    }

    /** The day the enrolment began; an empty string (before any date) when it has no start. */
    private function enrolledOn(StudentEnrolment $enrolment): string
    {
        return $enrolment->enrolled_on?->toDateString() ?? '';
    }

    /**
     * The school days from the day the student's enrolment began: max(range start, enrolled_on).
     *
     * @param  list<string>  $schoolDays
     * @return list<string>
     */
    private function sinceEnrolment(array $schoolDays, StudentEnrolment $enrolment): array
    {
        $from = $this->enrolledOn($enrolment);

        return $from === '' ? $schoolDays : array_values(array_filter($schoolDays, fn (string $d) => $d >= $from));
    }

    private function yearForMonth(string $firstDay): AcademicYear
    {
        $year = $this->years->findByYear((int) substr($firstDay, 0, 4));

        if ($year === null) {
            throw ValidationException::withMessages(['month' => ['There is no academic year for this month.']]);
        }

        return $year;
    }

    /**
     * @return array{string, string, string} the month (`Y-m`), its first and its last day
     */
    private function monthRange(?string $month): array
    {
        $month ??= CarbonImmutable::now(self::TIMEZONE)->format('Y-m');
        $first = CarbonImmutable::createFromFormat('!Y-m-d', "{$month}-01", 'UTC');

        return [$month, $first->toDateString(), $first->endOfMonth()->toDateString()];
    }

    /**
     * The school days from $from to $to, limited to the academic year and to today
     * ("so far"): holidays and weekly holidays excluded.
     *
     * @return list<string>
     */
    private function schoolDays(AcademicYear $year, string $from, string $to): array
    {
        $from = max($from, $year->start_date->toDateString());
        $to = min($to, $year->end_date->toDateString(), $this->today());

        if ($from > $to) {
            return [];
        }

        return SchoolDays::between(
            $from,
            $to,
            $this->institute->weeklyHolidays(),
            $this->holidays->between($from, $to)->keyBy('date')->all(),
        );
    }

    /**
     * Checks every entry against the sheet, collecting errors per row so nothing is
     * written when any row fails, and returns the rows ready to save.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\StudentEnrolment>  $onSheet  keyed by student id
     * @param  list<array{student_id: int, status: string, remarks?: ?string}>  $entries
     * @return list<array{student_id: int, enrolment_id: int, status: string, remarks: ?string}>
     */
    private function validatedRows($onSheet, array $entries): array
    {
        $errors = [];
        $rows = [];

        foreach ($entries as $i => $entry) {
            $enrolment = $onSheet->get((int) $entry['student_id']);

            if (! $enrolment) {
                $errors["entries.{$i}.student_id"][] = 'This student is not on the attendance sheet for this section.';

                continue;
            }

            $remarks = $entry['remarks'] ?? null;

            $rows[] = [
                'student_id' => (int) $entry['student_id'],
                'enrolment_id' => $enrolment->id,
                'status' => $entry['status'],
                'remarks' => $remarks === '' ? null : $remarks,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $rows;
    }
}
