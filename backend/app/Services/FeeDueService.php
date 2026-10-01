<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Exam;
use App\Models\FeeDue;
use App\Models\FeeHead;
use App\Models\FeeRate;
use App\Models\StudentEnrolment;
use App\Models\StudentFeeWaiver;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\ExamRepositoryInterface;
use App\Repositories\Contracts\FeeDueRepositoryInterface;
use App\Repositories\Contracts\FeeHeadRepositoryInterface;
use App\Repositories\Contracts\FeeRateRepositoryInterface;
use App\Repositories\Contracts\FeeWaiverRepositoryInterface;
use App\Repositories\Contracts\StudentEnrolmentRepositoryInterface;
use App\Support\Money;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lists fee dues and generates them for the active enrolments of an academic year.
 *
 * Generation rules (see docs/tasks/fees.md):
 *  - Without `exam_id`, the active monthly and one-time heads are generated; with it, only
 *    the per-exam heads (for the classes the exam is held for). A per-exam head needs an
 *    exam and any other kind refuses one.
 *  - A monthly head gets one due per month: the given `month`, or January up to the current
 *    month in Asia/Dhaka when none is given (all twelve for a past year, none for a future
 *    one). A student enrolled part-way through the year (`enrolled_on`) owes no month before
 *    the one they joined. A one-time head is charged once per enrolment (period `one_time`),
 *    a per-exam head once per exam (period `exam:{id}`).
 *  - The amount is the head's rate for the enrolment's class and year: the rate of the
 *    enrolment's group if there is one, otherwise the class-wide rate. No rate (or a rate of
 *    zero) means no due.
 *  - The student's waiver for the head and year is applied when the due is created: a
 *    percent (rounded half up to the paisa) or a fixed amount, never more than the amount.
 *    A fully waived due is created as `waived`.
 *  - The due date is the rate's `due_day` (default the 10th) in the month; a one-time due
 *    uses the given month, or the current one clamped into the year; an exam due falls on
 *    the exam's start date.
 *  - It is idempotent: an existing (enrolment, head, period) is skipped, never changed.
 * All of it is integer paisa (App\Support\Money).
 */
class FeeDueService
{
    private const DEFAULT_DUE_DAY = 10;

    private const TIMEZONE = 'Asia/Dhaka';

    public function __construct(
        private FeeDueRepositoryInterface $dues,
        private FeeHeadRepositoryInterface $heads,
        private FeeRateRepositoryInterface $rates,
        private FeeWaiverRepositoryInterface $waivers,
        private AcademicYearRepositoryInterface $years,
        private ExamRepositoryInterface $exams,
        private StudentEnrolmentRepositoryInterface $enrolments,
    ) {}

    /**
     * @param  array{student_id?: mixed, section_id?: mixed, class_id?: mixed, academic_year_id?: mixed, fee_head_id?: mixed, month?: string, status?: string}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->dues->paginate($filters, $perPage);
    }

    /**
     * Creates the missing dues (or, with $dryRun, only counts them) and returns
     * `created` (new dues), `skipped` (already existing) and `no_rate` (combinations with
     * no payable rate, so no due).
     *
     * @param  array{academic_year_id: int, month?: ?string, class_id?: ?int, section_id?: ?int, fee_head_id?: ?int, exam_id?: ?int}  $data
     * @return array{created: int, skipped: int, no_rate: int}
     */
    public function generate(array $data, bool $dryRun = false): array
    {
        $year = $this->years->findOrFail((int) $data['academic_year_id']);
        $month = $data['month'] ?? null;
        $examId = $data['exam_id'] ?? null;

        $this->ensureMonthInYear($month, $year);
        $exam = $examId !== null ? $this->examOfYear((int) $examId, $year) : null;
        $heads = $this->selectHeads($data['fee_head_id'] ?? null, $exam !== null);

        $classIds = $exam !== null ? $this->exams->classIds($exam) : null;
        $enrolments = $this->enrolments->activeInYear(
            $year->id,
            isset($data['class_id']) ? (int) $data['class_id'] : null,
            isset($data['section_id']) ? (int) $data['section_id'] : null,
            $classIds,
        );

        if ($heads->isEmpty() || $enrolments->isEmpty()) {
            return ['created' => 0, 'skipped' => 0, 'no_rate' => 0];
        }

        $rates = $this->ratesIndex($year, $heads, $enrolments);
        $waivers = $this->waiversIndex($year, $enrolments);
        $now = Carbon::now('UTC')->toDateTimeString();

        $rows = [];
        $noRate = 0;

        foreach ($heads as $head) {
            foreach ($this->periodsFor($head, $year, $month, $exam) as $period => $dueMonth) {
                foreach ($enrolments as $enrolment) {
                    if ($head->kind === FeeHead::KIND_MONTHLY && $this->joinedAfter($enrolment, $period)) {
                        continue;
                    }

                    $rate = $this->rateFor($rates, $head, $enrolment);
                    $amount = $rate !== null ? Money::toPaisa($rate->amount) : 0;

                    if ($amount <= 0) {
                        $noRate++;

                        continue;
                    }

                    $waived = $this->waiverAmount($waivers["{$enrolment->student_id}|{$head->id}"] ?? null, $amount);
                    $net = $amount - $waived;

                    $rows[] = [
                        'student_id' => $enrolment->student_id,
                        'enrolment_id' => $enrolment->id,
                        'fee_head_id' => $head->id,
                        'period' => $period,
                        'amount' => Money::fromPaisa($amount),
                        'waiver_amount' => Money::fromPaisa($waived),
                        'net_amount' => Money::fromPaisa($net),
                        'paid_amount' => Money::fromPaisa(0),
                        'status' => $net === 0 ? FeeDue::STATUS_WAIVED : FeeDue::STATUS_UNPAID,
                        'due_date' => $this->dueDate($head, $rate, $dueMonth, $exam),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        $existing = $rows === [] ? [] : $this->dues->existingKeys(
            array_values(array_unique(array_column($rows, 'enrolment_id'))),
            array_values(array_unique(array_column($rows, 'fee_head_id'))),
            array_values(array_unique(array_column($rows, 'period'))),
        );

        $missing = array_values(array_filter(
            $rows,
            fn (array $row) => ! isset($existing["{$row['enrolment_id']}|{$row['fee_head_id']}|{$row['period']}"]),
        ));

        $created = count($missing);

        if (! $dryRun && $missing !== []) {
            // insertOrIgnore also skips a due a concurrent run just created, so the count
            // is what was really inserted.
            $created = DB::transaction(fn () => $this->dues->insertMany($missing));
        }

        return ['created' => $created, 'skipped' => count($rows) - $created, 'no_rate' => $noRate];
    }

    private function ensureMonthInYear(?string $month, AcademicYear $year): void
    {
        if ($month !== null && ! str_starts_with($month, $year->year.'-')) {
            throw ValidationException::withMessages(['month' => ["The month must be in {$year->year}."]]);
        }
    }

    private function examOfYear(int $examId, AcademicYear $year): Exam
    {
        /** @var Exam $exam */
        $exam = $this->exams->findOrFail($examId);

        if ($exam->academic_year_id !== $year->id) {
            throw ValidationException::withMessages(['exam_id' => ['The exam is not in this academic year.']]);
        }

        return $exam;
    }

    /**
     * @return Collection<int, FeeHead>
     */
    private function selectHeads(?int $headId, bool $forExam): Collection
    {
        if ($headId !== null) {
            /** @var FeeHead $head */
            $head = $this->heads->findOrFail($headId);

            if (! $head->is_active) {
                throw ValidationException::withMessages(['fee_head_id' => ['The fee head is not active.']]);
            }

            if ($head->kind === FeeHead::KIND_PER_EXAM && ! $forExam) {
                throw ValidationException::withMessages(['exam_id' => ['Choose the exam to generate its fee.']]);
            }

            if ($head->kind !== FeeHead::KIND_PER_EXAM && $forExam) {
                throw ValidationException::withMessages(['fee_head_id' => ['Only a per-exam fee head can be generated for an exam.']]);
            }

            return collect([$head]);
        }

        return $this->heads->active()->filter(
            fn (FeeHead $head) => ($head->kind === FeeHead::KIND_PER_EXAM) === $forExam
        )->values();
    }

    /**
     * The periods to generate for a head, each with the month (`YYYY-MM`) its due date
     * falls in.
     *
     * @return array<string, string>
     */
    private function periodsFor(FeeHead $head, AcademicYear $year, ?string $month, ?Exam $exam): array
    {
        if ($head->kind === FeeHead::KIND_PER_EXAM) {
            return [FeeDue::examPeriod($exam->id) => $exam->start_date->format('Y-m')];
        }

        if ($head->kind === FeeHead::KIND_ONE_TIME) {
            return [FeeDue::PERIOD_ONE_TIME => $month ?? $this->currentMonthIn($year)];
        }

        if ($month !== null) {
            return [$month => $month];
        }

        $today = Carbon::now(self::TIMEZONE);
        $last = match (true) {
            $today->year > $year->year => 12,
            $today->year === $year->year => $today->month,
            default => 0,
        };

        $periods = [];

        for ($m = 1; $m <= $last; $m++) {
            $key = sprintf('%04d-%02d', $year->year, $m);
            $periods[$key] = $key;
        }

        return $periods;
    }

    /** The current Asia/Dhaka month, clamped into the academic year. */
    private function currentMonthIn(AcademicYear $year): string
    {
        $today = Carbon::now(self::TIMEZONE);

        return match (true) {
            $today->year > $year->year => sprintf('%04d-12', $year->year),
            $today->year < $year->year => sprintf('%04d-01', $year->year),
            default => $today->format('Y-m'),
        };
    }

    /** Whether the student joined after the month, so owes nothing for it. */
    private function joinedAfter(StudentEnrolment $enrolment, string $period): bool
    {
        return $enrolment->enrolled_on !== null && $enrolment->enrolled_on->format('Y-m') > $period;
    }

    /**
     * @param  Collection<int, FeeHead>  $heads
     * @param  EloquentCollection<int, StudentEnrolment>  $enrolments
     * @return array<string, FeeRate> keyed `{head}|{class}|{group}`
     */
    private function ratesIndex(AcademicYear $year, $heads, $enrolments): array
    {
        $index = [];

        $rates = $this->rates->forGeneration(
            $year->id,
            $heads->pluck('id')->all(),
            $enrolments->pluck('class_id')->unique()->values()->all(),
        );

        foreach ($rates as $rate) {
            $index["{$rate->fee_head_id}|{$rate->class_id}|{$rate->group}"] = $rate;
        }

        return $index;
    }

    /**
     * @return array<string, StudentFeeWaiver> keyed `{student}|{head}`
     */
    private function waiversIndex(AcademicYear $year, $enrolments): array
    {
        $index = [];

        foreach ($this->waivers->forGeneration($year->id, $enrolments->pluck('student_id')->unique()->values()->all()) as $waiver) {
            $index["{$waiver->student_id}|{$waiver->fee_head_id}"] = $waiver;
        }

        return $index;
    }

    /**
     * The group's own rate if the enrolment has a group and one exists, otherwise the
     * class-wide rate.
     *
     * @param  array<string, FeeRate>  $rates
     */
    private function rateFor(array $rates, FeeHead $head, StudentEnrolment $enrolment): ?FeeRate
    {
        return $rates["{$head->id}|{$enrolment->class_id}|{$enrolment->group}"]
            ?? $rates["{$head->id}|{$enrolment->class_id}|"]
            ?? null;
    }

    private function waiverAmount(?StudentFeeWaiver $waiver, int $amount): int
    {
        if ($waiver === null) {
            return 0;
        }

        $waived = $waiver->percent !== null
            ? Money::percentOf($amount, $waiver->percent)
            : Money::toPaisa($waiver->fixed_amount);

        return min($amount, max(0, $waived));
    }

    private function dueDate(FeeHead $head, FeeRate $rate, string $month, ?Exam $exam): string
    {
        if ($head->kind === FeeHead::KIND_PER_EXAM) {
            return $exam->start_date->toDateString();
        }

        return sprintf('%s-%02d', $month, $rate->due_day ?? self::DEFAULT_DUE_DAY);
    }
}
