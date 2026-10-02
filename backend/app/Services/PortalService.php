<?php

namespace App\Services;

use App\Models\ExamResult;
use App\Models\FeePayment;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the student and guardian portal (/portal) reads. It adds no queries of its own: every
 * read goes through the same service methods as `/api/my/*` (ownX() for a student,
 * childX() for a guardian's child), so the website and the API always return the same data.
 * This class only picks which of the two to call, and which child a guardian is looking at.
 */
class PortalService
{
    private const TIMEZONE = 'Asia/Dhaka';

    public function __construct(
        private StudentService $students,
        private ResultService $results,
        private AttendanceService $attendance,
        private FeeReportService $fees,
        private FeePaymentService $payments,
        private RoutineService $routines,
        private HomeworkService $homework,
    ) {}

    /**
     * The guardian's children, for the switcher. A student has none.
     */
    public function children(User $user): Collection
    {
        return $user->hasRole('parent') ? $this->students->childrenOf($user) : new Collection;
    }

    /**
     * The record the portal shows. A student always sees their own; `$requested` is ignored.
     * A guardian sees the requested child (403 unless the student is theirs), otherwise the
     * child remembered in the session if still theirs, otherwise their first child.
     */
    public function resolveStudent(User $user, ?int $requested, ?int $remembered): Student
    {
        $student = $this->pickStudent($user, $requested, $remembered);

        // The summary card shows the section's shift.
        if ($student->relationLoaded('currentEnrolment')) {
            $student->currentEnrolment?->loadMissing('section.shift');
        }

        return $student;
    }

    private function pickStudent(User $user, ?int $requested, ?int $remembered): Student
    {
        if ($user->hasRole('student')) {
            return $this->students->findOwn($user);
        }

        abort_unless($user->hasRole('parent'), 403);

        if ($requested !== null) {
            return $this->students->findChildOf($user, $requested);
        }

        $children = $this->students->childrenOf($user);
        $child = ($remembered !== null ? $children->firstWhere('id', $remembered) : null) ?? $children->first();

        abort_if($child === null, 404, 'No student is linked to this account.');

        return $child;
    }

    /**
     * @return array{attendance: array<string, mixed>, latest_result: ?ExamResult, fees: array<string, mixed>, exams: list<array{exam: \App\Models\Exam, subjects: list<\App\Models\ExamSubject>}>, homework_due_this_week: Collection}
     */
    public function dashboard(User $user, Student $student): array
    {
        return [
            'attendance' => $this->attendance($user, $student, null),
            'latest_result' => $this->results($user, $student)->first(),
            'fees' => $this->fees($user, $student),
            'exams' => $this->upcomingExams($user, $student),
            'homework_due_this_week' => $this->homework->dueThisWeek($student),
        ];
    }

    /**
     * The student's published results, newest first.
     */
    public function results(User $user, Student $student): Collection
    {
        return $user->hasRole('student')
            ? $this->results->ownResults($user)
            : $this->results->childResults($user, $student->id);
    }

    /**
     * One published exam's result for the marksheet. 404 for an exam that is unpublished or
     * that the student has no result in.
     */
    public function result(User $user, Student $student, int $examId): ExamResult
    {
        $result = $this->results($user, $student)->firstWhere('exam_id', $examId);

        abort_if($result === null, 404, 'Record not found.');

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function attendance(User $user, Student $student, ?string $month): array
    {
        return $user->hasRole('student')
            ? $this->attendance->ownMonth($user, $month)
            : $this->attendance->childMonth($user, $student->id, $month);
    }

    /**
     * @return array<string, mixed>
     */
    public function fees(User $user, Student $student): array
    {
        return $user->hasRole('student')
            ? $this->fees->ownFees($user)
            : $this->fees->childFees($user, $student->id);
    }

    /**
     * The routine of the student's section, the same data as `/api/my/routine`.
     *
     * @return array{academic_year: ?\App\Models\AcademicYear, section: ?\App\Models\Section, days: list<string>, periods: Collection, slots: Collection}
     */
    public function routine(Student $student): array
    {
        return $this->routines->forStudent($student);
    }

    /**
     * The student's homework for the subjects they take, the same data as
     * `/api/my/homework`. Filters: `from`, `to`, `due`.
     *
     * @param  array<string, mixed>  $filters
     */
    public function homework(Student $student, array $filters = []): Collection
    {
        return $this->homework->forStudent($student, $filters);
    }

    /**
     * The student's own payment with its receipt data. 404 for anyone else's.
     */
    public function receipt(Student $student, int $paymentId): FeePayment
    {
        return $this->payments->findForStudent($student, $paymentId);
    }

    /**
     * The exams that are on now or still to come, each with only the subjects the student
     * takes. An exam whose last date has passed is left out.
     *
     * @return list<array{exam: \App\Models\Exam, subjects: list<\App\Models\ExamSubject>}>
     */
    public function upcomingExams(User $user, Student $student): array
    {
        $entries = $this->results->ownSchedule($user);
        $entry = collect($entries)->first(fn (array $e) => $e['student']->id === $student->id);
        $today = CarbonImmutable::now(self::TIMEZONE)->toDateString();

        return array_values(array_filter($entry['exams'] ?? [], function (array $item) use ($today) {
            $last = $item['exam']->end_date?->toDateString()
                ?? collect($item['subjects'])->map(fn ($s) => $s->exam_date?->toDateString())->filter()->max();

            return $last === null || $last >= $today;
        }));
    }
}
