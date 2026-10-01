<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\Classes;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\FeePayment;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\DashboardRepositoryInterface;
use App\Repositories\Contracts\HolidayRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\AcademicGroup;
use App\Support\Money;
use App\Support\SchoolDays;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The dashboard: one computed payload per role, all for the active academic year and for
 * "today" in Asia/Dhaka. A user with several roles gets the widest view (admin > office >
 * teacher); anyone else is refused. Every figure comes from DashboardRepository's
 * aggregate queries, so the number of queries depends on the role and never on how many
 * students or sections there are; money is formatted from integer paisa (App\Support\Money)
 * and percentages as `decimal:2` strings (App\Support\SchoolDays::percentage()).
 *
 * "Attended" counts present and late students together, like the attendance reports. A
 * month's "collected" is what has been paid against that month's dues (cancelled payments
 * are already reversed there), so collected + outstanding always equals the net dues.
 */
class DashboardService
{
    private const TIMEZONE = 'Asia/Dhaka';

    private const ADMIN_RECENT = 5;

    private const OFFICE_RECENT_RECEIPTS = 10;

    public function __construct(
        private DashboardRepositoryInterface $dashboard,
        private AcademicYearRepositoryInterface $years,
        private UserRepositoryInterface $users,
        private HolidayRepositoryInterface $holidays,
        private InstituteSettingsService $institute,
        private TeacherScope $teacherScope,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $role = $this->roleOf($user);
        $now = CarbonImmutable::now(self::TIMEZONE);
        $year = $this->years->findActive();

        $base = [
            'role' => $role,
            'today' => $now->toDateString(),
            'academic_year' => $year === null ? null : ['id' => $year->id, 'year' => $year->year, 'name' => $year->name],
        ];

        // Nothing is counted without an active year; the screen shows an empty state.
        if ($year === null) {
            return $base;
        }

        return $base + match ($role) {
            'admin' => $this->admin($year, $now),
            'office' => $this->office($year, $now),
            default => $this->teacher($user, $year, $now),
        };
    }

    private function roleOf(User $user): string
    {
        $roles = $this->users->roleNames($user);

        foreach (['admin', 'office', 'teacher'] as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        abort(403);
    }

    // --- admin ---------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function admin(AcademicYear $year, CarbonImmutable $now): array
    {
        $sections = $this->dashboard->sections(null)->keyBy('id');
        $groups = $this->dashboard->enrolmentGroups($year->id, null);

        return [
            'counts' => $this->counts($sections, $groups),
            'attendance_today' => $this->attendanceToday($year, $now->toDateString(), $sections, $groups, null),
            'exams' => $this->adminExams($year, $sections, $groups),
            'fees' => [
                'month' => $this->month($year, $now),
                'today' => $this->collectionToday($now),
                'overdue' => $this->overdue($year, $now),
            ],
            'recent' => [
                'payments' => $this->payments($this->dashboard->recentPayments(self::ADMIN_RECENT)),
                'exams' => $this->dashboard->recentExams($year->id, self::ADMIN_RECENT)
                    ->map(fn (Exam $exam) => $this->examSummary($exam) + ['updated_at' => $exam->updated_at?->toIso8601String()])
                    ->all(),
                'students' => $this->dashboard->recentStudents($year->id, self::ADMIN_RECENT)
                    ->map(fn (Student $student) => $this->recentStudent($student))
                    ->all(),
            ],
        ];
    }

    /**
     * @param  Collection<int, Section>  $sections  keyed by id
     * @param  Collection<int, object>  $groups
     * @return array<string, mixed>
     */
    private function counts(Collection $sections, Collection $groups): array
    {
        $byLevel = array_fill_keys(Classes::LEVELS, 0);
        $byGroup = array_fill_keys(AcademicGroup::VALUES, 0);
        $byShift = [];
        $total = 0;

        foreach ($sections as $section) {
            if ($section->shift !== null) {
                $byShift[$section->shift_id] ??= ['shift' => $this->shiftSummary($section), 'students' => 0];
            }
        }

        foreach ($groups as $row) {
            $section = $sections->get($row->section_id);
            $students = (int) $row->students;

            if ($section === null) {
                continue;
            }

            $total += $students;

            if ($section->class !== null) {
                $byLevel[Classes::levelForNumber((int) $section->class->number)] += $students;
            }

            if ($row->group !== null && isset($byGroup[$row->group])) {
                $byGroup[$row->group] += $students;
            }

            if (isset($byShift[$section->shift_id])) {
                $byShift[$section->shift_id]['students'] += $students;
            }
        }

        $staff = $this->dashboard->staffCounts();

        return [
            'students' => [
                'total' => $total,
                'by_level' => $byLevel,
                'by_group' => $byGroup,
                'by_shift' => array_values($byShift),
            ],
            'staff' => ['active' => $staff['total'], 'teachers' => $staff['teachers'], 'with_login' => $staff['with_login']],
            'sections' => $sections->filter(fn (Section $section) => $section->is_active)->count(),
        ];
    }

    /**
     * @param  Collection<int, Section>  $sections
     * @param  Collection<int, object>  $groups
     * @return array<string, mixed>
     */
    private function adminExams(AcademicYear $year, Collection $sections, Collection $groups): array
    {
        $latest = $this->dashboard->latestExam($year->id);

        return [
            'latest' => $latest === null ? null : $this->examSummary($latest),
            'classes' => $latest === null ? [] : $this->dashboard->resultsByClass($latest->id)
                ->map(fn (object $row) => [
                    'class' => ['id' => (int) $row->class_id, 'number' => (int) $row->class_number, 'name' => $row->class_name],
                    'students' => (int) $row->students,
                    'passed' => (int) $row->passed,
                    'pass_rate' => SchoolDays::percentage((int) $row->passed, (int) $row->students),
                    'top_gpa' => number_format((float) $row->top_gpa, 2, '.', ''),
                ])->values()->all(),
            'marks_entry' => $this->incompleteSheets($year, $sections, $groups),
        ];
    }

    /**
     * The mark sheets of the exams in marks entry that still lack marks, per exam.
     *
     * @param  Collection<int, Section>  $sections
     * @param  Collection<int, object>  $groups
     * @return array{incomplete_sheets: int, total_sheets: int, exams: list<array<string, mixed>>}
     */
    private function incompleteSheets(AcademicYear $year, Collection $sections, Collection $groups): array
    {
        $sheets = $this->markSheets($year, $sections, $groups, null, null);
        $exams = [];

        foreach ($sheets as $sheet) {
            $row = &$exams[$sheet['exam']['id']];
            $row ??= ['exam' => $sheet['exam'], 'sheets' => 0, 'incomplete' => 0];
            $row['sheets']++;
            $row['incomplete'] += $sheet['entered'] < $sheet['total'] ? 1 : 0;
            unset($row);
        }

        return [
            'incomplete_sheets' => array_sum(array_column($exams, 'incomplete')),
            'total_sheets' => count($sheets),
            'exams' => array_values($exams),
        ];
    }

    // --- office --------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function office(AcademicYear $year, CarbonImmutable $now): array
    {
        return [
            'collection_today' => $this->collectionToday($now),
            'month' => $this->month($year, $now),
            'students_with_outstanding_dues' => $this->dashboard->studentsWithOutstandingDues($year->id),
            'recent_payments' => $this->payments($this->dashboard->recentPayments(self::OFFICE_RECENT_RECEIPTS)),
        ];
    }

    // --- teacher -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function teacher(User $user, AcademicYear $year, CarbonImmutable $now): array
    {
        $context = $this->teacherScope->forUser($user, $year->id);
        $leading = $context?->leadingSectionIds() ?? [];
        $teaching = $context?->teachingSectionIds() ?? [];
        $allIds = array_values(array_unique([...$leading, ...$teaching]));

        $sections = $allIds === [] ? collect() : $this->dashboard->sections($allIds)->keyBy('id');
        $groups = $allIds === [] ? collect() : $this->dashboard->enrolmentGroups($year->id, $allIds);

        $assignments = $context?->assignments ?? collect();
        $allowed = fn (ExamSubject $subject, int $sectionId): bool => $assignments->contains(
            fn ($a) => (int) $a->section_id === $sectionId
                && (int) $a->class_id === (int) $subject->class_id
                && (int) $a->subject_id === (int) $subject->subject_id
        );

        return [
            'attendance_today' => $this->attendanceToday($year, $now->toDateString(), $sections, $groups, $leading),
            'mark_sheets' => $teaching === [] ? [] : $this->markSheets($year, $sections, $groups, $teaching, $allowed),
            'pass_rates' => $this->passRates($year, $sections, $leading),
        ];
    }

    /**
     * The latest exam's pass rate in each section the teacher leads.
     *
     * @param  Collection<int, Section>  $sections
     * @param  list<int>  $leading
     * @return array<string, mixed>
     */
    private function passRates(AcademicYear $year, Collection $sections, array $leading): array
    {
        $latest = $leading === [] ? null : $this->dashboard->latestExam($year->id);

        if ($latest === null) {
            return ['exam' => null, 'sections' => []];
        }

        return [
            'exam' => $this->examSummary($latest),
            'sections' => $this->dashboard->resultsBySection($latest->id, $leading)
                ->map(fn (object $row) => [
                    'section' => $this->sectionSummary($sections->get($row->section_id)),
                    'students' => (int) $row->students,
                    'passed' => (int) $row->passed,
                    'pass_rate' => SchoolDays::percentage((int) $row->passed, (int) $row->students),
                ])->values()->all(),
        ];
    }

    // --- shared blocks -------------------------------------------------------------------

    /**
     * Today's attendance by section: which sections with students have been marked and the
     * present percentage. A listed holiday or weekly holiday makes it no school day, and
     * then nothing is "not marked".
     *
     * @param  Collection<int, Section>  $sections  keyed by id
     * @param  Collection<int, object>  $groups
     * @param  list<int>|null  $scope  only these sections (a teacher's), or null for all
     * @return array<string, mixed>
     */
    private function attendanceToday(AcademicYear $year, string $today, Collection $sections, Collection $groups, ?array $scope): array
    {
        $holiday = $this->holidayOn($today);
        $isSchoolDay = $holiday === null;

        $students = [];
        foreach ($groups as $row) {
            $students[$row->section_id] = ($students[$row->section_id] ?? 0) + (int) $row->students;
        }

        $listed = $sections
            ->filter(fn (Section $section) => $section->is_active
                && ($students[$section->id] ?? 0) > 0
                && ($scope === null || in_array($section->id, $scope, true)))
            ->values();

        $counts = [];
        if ($listed->isNotEmpty()) {
            foreach ($this->dashboard->attendanceCounts($year->id, $today, $scope) as $row) {
                $counts[$row->section_id][$row->status] = (int) $row->total;
            }
        }

        $rows = [];
        $notMarked = [];
        $attended = 0;
        $recorded = 0;

        foreach ($listed as $section) {
            $by = $counts[$section->id] ?? [];
            $total = array_sum($by);
            $present = $by[Attendance::STATUS_PRESENT] ?? 0;
            $late = $by[Attendance::STATUS_LATE] ?? 0;
            $marked = $total > 0;

            $attended += $present + $late;
            $recorded += $total;

            if (! $marked && $isSchoolDay) {
                $notMarked[] = $this->sectionSummary($section);
            }

            $rows[] = [
                'section' => $this->sectionSummary($section),
                'students' => $students[$section->id],
                'marked' => $marked,
                'present' => $present,
                'absent' => $by[Attendance::STATUS_ABSENT] ?? 0,
                'late' => $late,
                'leave' => $by[Attendance::STATUS_LEAVE] ?? 0,
                'percentage' => $marked ? SchoolDays::percentage($present + $late, $total) : null,
            ];
        }

        $markedCount = count(array_filter($rows, fn (array $row) => $row['marked']));

        return [
            'date' => $today,
            'is_school_day' => $isSchoolDay,
            'holiday' => $holiday,
            'sections_marked' => $markedCount,
            'sections_not_marked' => $isSchoolDay ? count($rows) - $markedCount : 0,
            'percentage' => $recorded > 0 ? SchoolDays::percentage($attended, $recorded) : null,
            'sections' => $rows,
            'not_marked' => $notMarked,
        ];
    }

    /**
     * The mark sheets (one exam subject in one section) of the exams in marks entry, with
     * how many students should have marks and how many do. Who takes a subject is decided
     * by StudentEnrolment::takes(), the same rule the mark sheets use, applied to the
     * enrolment groups so no query runs per student or per section.
     *
     * @param  Collection<int, Section>  $sections  keyed by id
     * @param  Collection<int, object>  $groups
     * @param  list<int>|null  $sectionIds  only these sections (a teacher's), or null for all
     * @param  (callable(ExamSubject, int): bool)|null  $allowed  extra filter per sheet
     * @return list<array<string, mixed>>
     */
    private function markSheets(AcademicYear $year, Collection $sections, Collection $groups, ?array $sectionIds, ?callable $allowed): array
    {
        $subjects = $this->dashboard->openExamSubjects($year->id);

        if ($subjects->isEmpty()) {
            return [];
        }

        $entered = [];
        foreach ($this->dashboard->enteredMarkCounts($subjects->modelKeys(), $sectionIds) as $row) {
            $entered[$row->exam_subject_id][$row->section_id] = (int) $row->entered;
        }

        $sheets = [];

        foreach ($subjects as $subject) {
            $totals = [];

            foreach ($groups as $row) {
                $section = $sections->get($row->section_id);

                if ($section === null || (int) $section->class_id !== (int) $subject->class_id) {
                    continue;
                }

                if ($allowed !== null && ! $allowed($subject, (int) $row->section_id)) {
                    continue;
                }

                $enrolment = new StudentEnrolment(['group' => $row->group, 'optional_subject_id' => $row->optional_subject_id]);

                if ($enrolment->takes($subject)) {
                    $totals[$row->section_id] = ($totals[$row->section_id] ?? 0) + (int) $row->students;
                }
            }

            foreach ($totals as $sectionId => $total) {
                $sheets[] = [
                    'exam' => ['id' => $subject->exam->id, 'name' => $subject->exam->displayName()],
                    'subject' => ['id' => $subject->subject_id, 'name' => $subject->subject?->name],
                    'group' => $subject->group,
                    'section' => $this->sectionSummary($sections->get($sectionId)),
                    'entered' => $entered[$subject->id][$sectionId] ?? 0,
                    'total' => $total,
                ];
            }
        }

        return $sheets;
    }

    /**
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
     * This month's dues (by due date): net, collected against them and still outstanding.
     *
     * @return array<string, string>
     */
    private function month(AcademicYear $year, CarbonImmutable $now): array
    {
        $totals = $this->dashboard->dueTotals($year->id, $now->startOfMonth()->toDateString(), $now->endOfMonth()->toDateString());
        $net = Money::toPaisa($totals['net'] ?? 0);
        $paid = Money::toPaisa($totals['paid'] ?? 0);

        return [
            'month' => $now->format('Y-m'),
            'net_amount' => Money::fromPaisa($net),
            'collected_amount' => Money::fromPaisa($paid),
            'outstanding_amount' => Money::fromPaisa($net - $paid),
        ];
    }

    /**
     * Today's (Asia/Dhaka day) received payments in total and by every method.
     *
     * @return array<string, mixed>
     */
    private function collectionToday(CarbonImmutable $now): array
    {
        $rows = $this->dashboard->collectionByMethod($now->startOfDay()->utc(), $now->addDay()->startOfDay()->utc())->keyBy('method');
        $total = 0;
        $count = 0;
        $byMethod = [];

        foreach (FeePayment::METHODS as $method) {
            $row = $rows->get($method);
            $paisa = Money::toPaisa($row->total ?? 0);
            $total += $paisa;
            $count += (int) ($row->payments ?? 0);
            $byMethod[] = ['method' => $method, 'count' => (int) ($row->payments ?? 0), 'amount' => Money::fromPaisa($paisa)];
        }

        return ['count' => $count, 'amount' => Money::fromPaisa($total), 'by_method' => $byMethod];
    }

    /**
     * @return array{count: int, outstanding_amount: string}
     */
    private function overdue(AcademicYear $year, CarbonImmutable $now): array
    {
        $totals = $this->dashboard->overdueTotals($year->id, $now->toDateString());

        return [
            'count' => $totals['count'],
            'outstanding_amount' => Money::fromPaisa(Money::toPaisa($totals['outstanding'] ?? 0)),
        ];
    }

    /**
     * @param  iterable<FeePayment>  $payments
     * @return list<array<string, mixed>>
     */
    private function payments(iterable $payments): array
    {
        $out = [];

        foreach ($payments as $payment) {
            $out[] = [
                'id' => $payment->id,
                'receipt_no' => $payment->receipt_no,
                'student' => $payment->student === null ? null : [
                    'id' => $payment->student->id,
                    'student_id' => $payment->student->student_id,
                    'name_en' => $payment->student->name_en,
                    'name_bn' => $payment->student->name_bn,
                ],
                'amount' => $payment->amount,
                'method' => $payment->method,
                'paid_at' => $payment->paid_at->toIso8601String(),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function recentStudent(Student $student): array
    {
        $enrolment = $student->currentEnrolment;

        return [
            'id' => $student->id,
            'student_id' => $student->student_id,
            'name_en' => $student->name_en,
            'name_bn' => $student->name_bn,
            'admission_date' => $student->admission_date?->toDateString(),
            'class' => $enrolment?->class === null ? null : ['id' => $enrolment->class->id, 'number' => $enrolment->class->number, 'name' => $enrolment->class->name],
            'section' => $enrolment?->section === null ? null : ['id' => $enrolment->section->id, 'name' => $enrolment->section->name],
        ];
    }

    /**
     * @return array{id: int, name: string, status: string}
     */
    private function examSummary(Exam $exam): array
    {
        return ['id' => $exam->id, 'name' => $exam->displayName(), 'status' => $exam->status];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sectionSummary(?Section $section): ?array
    {
        if ($section === null) {
            return null;
        }

        return [
            'id' => $section->id,
            'name' => $section->name,
            'code' => $section->code,
            'class' => $section->class === null ? null : ['id' => $section->class->id, 'number' => $section->class->number, 'name' => $section->class->name],
            'shift' => $this->shiftSummary($section),
        ];
    }

    /**
     * @return array{id: int, name_en: ?string, name_bn: ?string}|null
     */
    private function shiftSummary(Section $section): ?array
    {
        return $section->shift === null ? null : [
            'id' => $section->shift->id,
            'name_en' => $section->shift->name_en,
            'name_bn' => $section->shift->name_bn,
        ];
    }
}
