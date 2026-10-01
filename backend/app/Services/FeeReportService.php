<?php

namespace App\Services;

use App\Models\FeeDue;
use App\Models\FeePayment;
use App\Models\Student;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\FeeDueRepositoryInterface;
use App\Repositories\Contracts\FeeReportRepositoryInterface;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Fee reports and a student's own fee page, all computed in integer paisa and sent as
 * `decimal:2` strings (App\Support\Money). Dates are Asia/Dhaka calendar dates; payment
 * times are UTC in the database.
 *
 *  - dues(): per student of a section, the net, paid and outstanding amounts, for a month
 *    (by due date) or the whole year.
 *  - collection(): payments in a date range totalled by method and by collector, with the
 *    receipts listed; cancelled payments are listed (flagged) but never counted.
 *  - ledger(): a student's dues and payments for a year in date order with a running
 *    balance (billed minus paid); a cancelled payment is shown but does not move it.
 *  - ownFees()/childFees(): a student's (or a guardian's child's) dues, payments and
 *    outstanding total, the same data the portal and the admin screens read.
 */
class FeeReportService
{
    private const TIMEZONE = 'Asia/Dhaka';

    public function __construct(
        private FeeReportRepositoryInterface $reports,
        private FeeDueRepositoryInterface $dues,
        private AcademicYearRepositoryInterface $years,
        private StudentService $students,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dues(int $sectionId, ?int $academicYearId, ?string $month): array
    {
        $yearId = $this->yearId($academicYearId);
        $rows = [];

        foreach ($this->reports->duesInSection($sectionId, $yearId, $month) as $due) {
            $row = &$rows[$due->student_id];
            $row ??= [
                'student' => $this->studentSummary($due->student),
                'roll_number' => $due->enrolment?->roll_number,
                'net' => 0,
                'paid' => 0,
                'dues' => 0,
            ];
            $row['net'] += Money::toPaisa($due->net_amount);
            $row['paid'] += Money::toPaisa($due->paid_amount);
            $row['dues']++;
            unset($row);
        }

        uasort($rows, fn (array $a, array $b) => [$a['roll_number'] ?? PHP_INT_MAX, $a['student']['student_id'] ?? ''] <=> [$b['roll_number'] ?? PHP_INT_MAX, $b['student']['student_id'] ?? '']);

        $totals = ['net' => 0, 'paid' => 0];
        $out = [];

        foreach ($rows as $row) {
            $totals['net'] += $row['net'];
            $totals['paid'] += $row['paid'];
            $out[] = [
                'student' => $row['student'],
                'roll_number' => $row['roll_number'],
                'due_count' => $row['dues'],
                'net_amount' => Money::fromPaisa($row['net']),
                'paid_amount' => Money::fromPaisa($row['paid']),
                'outstanding_amount' => Money::fromPaisa($row['net'] - $row['paid']),
            ];
        }

        return [
            'academic_year_id' => $yearId,
            'section_id' => $sectionId,
            'month' => $month,
            'rows' => $out,
            'totals' => [
                'net_amount' => Money::fromPaisa($totals['net']),
                'paid_amount' => Money::fromPaisa($totals['paid']),
                'outstanding_amount' => Money::fromPaisa($totals['net'] - $totals['paid']),
            ],
        ];
    }

    /**
     * @param  string  $from  Asia/Dhaka date (Y-m-d), inclusive
     * @param  string  $to  Asia/Dhaka date (Y-m-d), inclusive
     * @param  array{method?: ?string, collected_by?: ?int}  $filters
     * @return array<string, mixed>
     */
    public function collection(string $from, string $to, array $filters = []): array
    {
        $start = Carbon::parse($from, self::TIMEZONE)->startOfDay()->utc();
        $end = Carbon::parse($to, self::TIMEZONE)->addDay()->startOfDay()->utc();

        $payments = $this->reports->paymentsBetween($start, $end, $filters);
        $counted = $payments->reject(fn (FeePayment $payment) => $payment->isCancelled());

        $byMethod = array_fill_keys(FeePayment::METHODS, ['count' => 0, 'amount' => 0]);
        $byCollector = [];
        $total = 0;

        foreach ($counted as $payment) {
            $paisa = Money::toPaisa($payment->amount);
            $total += $paisa;
            $byMethod[$payment->method]['count']++;
            $byMethod[$payment->method]['amount'] += $paisa;

            $collector = &$byCollector[$payment->collected_by];
            $collector ??= ['user_id' => $payment->collected_by, 'name' => $payment->collector?->name, 'count' => 0, 'amount' => 0];
            $collector['count']++;
            $collector['amount'] += $paisa;
            unset($collector);
        }

        return [
            'from' => $from,
            'to' => $to,
            'totals' => ['count' => $counted->count(), 'amount' => Money::fromPaisa($total)],
            'by_method' => array_map(
                fn (string $method) => ['method' => $method, 'count' => $byMethod[$method]['count'], 'amount' => Money::fromPaisa($byMethod[$method]['amount'])],
                FeePayment::METHODS,
            ),
            'by_collector' => array_values(array_map(
                fn (array $row) => [...$row, 'amount' => Money::fromPaisa($row['amount'])],
                $byCollector,
            )),
            'receipts' => $payments->map(fn (FeePayment $payment) => [
                'id' => $payment->id,
                'receipt_no' => $payment->receipt_no,
                'paid_at' => $payment->paid_at->toIso8601String(),
                'student' => $this->studentSummary($payment->student),
                'method' => $payment->method,
                'transaction_id' => $payment->transaction_id,
                'amount' => $payment->amount,
                'collected_by' => $payment->collected_by,
                'collector_name' => $payment->collector?->name,
                'is_cancelled' => $payment->isCancelled(),
            ])->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ledger(Student $student, ?int $academicYearId): array
    {
        $yearId = $this->yearId($academicYearId);
        $entries = [];

        foreach ($this->reports->duesOfStudentInYear($student->id, $yearId) as $due) {
            $entries[] = [
                'sort' => [$due->due_date->toDateString(), 0, $due->id],
                'type' => 'due',
                'id' => $due->id,
                'date' => $due->due_date->toDateString(),
                'reference' => $due->period,
                'name_en' => $due->head?->name_en,
                'name_bn' => $due->head?->name_bn,
                'debit' => Money::toPaisa($due->net_amount),
                'credit' => 0,
                'is_cancelled' => false,
            ];
        }

        $byPayment = [];

        foreach ($this->reports->allocationsOfStudentInYear($student->id, $yearId) as $allocation) {
            $byPayment[$allocation->fee_payment_id]['payment'] = $allocation->payment;
            $byPayment[$allocation->fee_payment_id]['amount'] = ($byPayment[$allocation->fee_payment_id]['amount'] ?? 0) + Money::toPaisa($allocation->amount);
        }

        foreach ($byPayment as $row) {
            /** @var FeePayment $payment */
            $payment = $row['payment'];
            $date = $payment->paid_at->copy()->setTimezone(self::TIMEZONE)->toDateString();

            $entries[] = [
                'sort' => [$date, 1, $payment->id],
                'type' => 'payment',
                'id' => $payment->id,
                'date' => $date,
                'reference' => $payment->receipt_no,
                'name_en' => $payment->method,
                'name_bn' => null,
                'debit' => 0,
                'credit' => $payment->isCancelled() ? 0 : $row['amount'],
                'is_cancelled' => $payment->isCancelled(),
            ];
        }

        usort($entries, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);

        $balance = 0;
        $billed = 0;
        $paid = 0;
        $out = [];

        foreach ($entries as $entry) {
            $balance += $entry['debit'] - $entry['credit'];
            $billed += $entry['debit'];
            $paid += $entry['credit'];
            unset($entry['sort']);
            $out[] = [
                ...$entry,
                'debit' => Money::fromPaisa($entry['debit']),
                'credit' => Money::fromPaisa($entry['credit']),
                'balance' => Money::fromPaisa($balance),
            ];
        }

        return [
            'student' => $this->studentSummary($student),
            'academic_year_id' => $yearId,
            'entries' => $out,
            'totals' => [
                'billed' => Money::fromPaisa($billed),
                'paid' => Money::fromPaisa($paid),
                'balance' => Money::fromPaisa($balance),
            ],
        ];
    }

    /**
     * The signed-in student's own dues, payments and outstanding total.
     *
     * @return array<string, mixed>
     */
    public function ownFees(User $user): array
    {
        return $this->studentFees($this->students->findOwn($user));
    }

    /**
     * A child's fees for their guardian. 403 unless the student is the guardian's own child.
     *
     * @return array<string, mixed>
     */
    public function childFees(User $guardian, int $studentId): array
    {
        return $this->studentFees($this->students->findChildOf($guardian, $studentId));
    }

    /**
     * @return array{student: Student, outstanding_total: string, dues: \Illuminate\Support\Collection, payments: \Illuminate\Support\Collection}
     */
    private function studentFees(Student $student): array
    {
        $dues = $this->dues->allForStudent($student->id);

        return [
            'student' => $student,
            'outstanding_total' => Money::fromPaisa($dues->sum(fn (FeeDue $due) => $due->outstandingPaisa())),
            'dues' => $dues,
            'payments' => $this->reports->paymentsOfStudent($student->id),
        ];
    }

    private function yearId(?int $academicYearId): int
    {
        $yearId = $academicYearId ?? $this->years->findActive()?->id;

        if ($yearId === null) {
            throw ValidationException::withMessages(['academic_year_id' => ['There is no active academic year. Choose one.']]);
        }

        return $yearId;
    }

    /**
     * @return array{id: int, student_id: string, name_en: ?string, name_bn: ?string}|null
     */
    private function studentSummary(?Student $student): ?array
    {
        return $student === null ? null : [
            'id' => $student->id,
            'student_id' => $student->student_id,
            'name_en' => $student->name_en,
            'name_bn' => $student->name_bn,
        ];
    }
}
