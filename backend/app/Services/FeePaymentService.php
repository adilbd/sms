<?php

namespace App\Services;

use App\Models\FeeDue;
use App\Models\FeePayment;
use App\Models\User;
use App\Repositories\Contracts\FeeDueRepositoryInterface;
use App\Repositories\Contracts\FeePaymentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\Money;
use App\Support\UniqueViolation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Taking fee payments and cancelling them. Everything runs in one transaction with the
 * student's row locked first (then the receipt counter, or the payment being cancelled),
 * so two clerks can't spend the same outstanding amount twice and receipt numbers never
 * repeat. All amounts are integer paisa (App\Support\Money).
 *
 *  - A payment can't exceed what is outstanding: on all the student's open dues, or on the
 *    chosen `due_ids` when given. It is allocated to dues oldest due date first (or in the
 *    order of `due_ids`), each due moving to `partial` or `paid`.
 *  - bKash, Nagad and Rocket need a transaction ID, unique per method (a cancelled
 *    payment keeps its ID reserved). Cash takes none.
 *  - The receipt number is `{year}-{000001}` from a per-year counter, the year being the
 *    current Asia/Dhaka year; a cancelled payment keeps its number.
 *  - `paid_at` defaults to now (UTC); only an admin may send one (a backdated entry).
 *  - Cancelling (admin only, enforced by the route permission) marks the payment and
 *    recomputes each due it paid from its remaining non-cancelled allocations.
 */
class FeePaymentService
{
    private const TIMEZONE = 'Asia/Dhaka';

    public function __construct(
        private FeePaymentRepositoryInterface $payments,
        private FeeDueRepositoryInterface $dues,
        private UserRepositoryInterface $users,
    ) {}

    /**
     * @param  array{student_id?: mixed, method?: string, collected_by?: mixed, status?: string, search?: string, from?: string, to?: string}  $filters  `from`/`to` are Asia/Dhaka dates
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        if (filled($filters['from'] ?? null)) {
            $filters['from'] = Carbon::parse($filters['from'], self::TIMEZONE)->startOfDay()->utc();
        }

        if (filled($filters['to'] ?? null)) {
            $filters['to'] = Carbon::parse($filters['to'], self::TIMEZONE)->addDay()->startOfDay()->utc();
        }

        return $this->payments->paginate($filters, $perPage);
    }

    public function find(FeePayment $payment): FeePayment
    {
        return $this->payments->loadReceipt($payment);
    }

    /**
     * @param  array{student_id: int, amount: string|int|float, method: string, transaction_id?: ?string, paid_at?: ?string, note?: ?string, due_ids?: ?list<int>}  $data
     */
    public function collect(array $data, User $collector): FeePayment
    {
        $method = $data['method'];
        $transactionId = $this->normalizeTransactionId($data['transaction_id'] ?? null);
        $paidAt = $this->paidAt($data['paid_at'] ?? null, $collector);
        $amount = Money::toPaisa($data['amount']);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['The amount must be more than zero.']]);
        }

        if (in_array($method, FeePayment::MOBILE_METHODS, true)) {
            if ($transactionId === null) {
                throw ValidationException::withMessages(['transaction_id' => ['A transaction ID is required for this method.']]);
            }

            if ($this->payments->transactionIdTaken($method, $transactionId)) {
                throw $this->duplicateTransaction();
            }
        } else {
            $transactionId = null;
        }

        try {
            $payment = DB::transaction(function () use ($data, $collector, $method, $transactionId, $paidAt, $amount) {
                $student = $this->payments->lockStudent((int) $data['student_id']);
                $dues = $this->duesToPay($student->id, $data['due_ids'] ?? null);

                $outstanding = $dues->sum(fn (FeeDue $due) => $due->outstandingPaisa());

                if ($amount > $outstanding) {
                    throw ValidationException::withMessages([
                        'amount' => ['The amount is more than the outstanding '.Money::fromPaisa($outstanding).'.'],
                    ]);
                }

                $year = Carbon::now(self::TIMEZONE)->year;
                $number = $this->payments->nextReceiptNumber($year);

                $payment = $this->payments->create([
                    'receipt_no' => sprintf('%d-%06d', $year, $number),
                    'student_id' => $student->id,
                    'paid_at' => $paidAt,
                    'method' => $method,
                    'transaction_id' => $transactionId,
                    'amount' => Money::fromPaisa($amount),
                    'collected_by' => $collector->id,
                    'note' => $data['note'] ?? null,
                ]);

                $remaining = $amount;

                foreach ($dues as $due) {
                    if ($remaining === 0) {
                        break;
                    }

                    $part = min($remaining, $due->outstandingPaisa());
                    $paid = Money::toPaisa($due->paid_amount) + $part;

                    $this->payments->addAllocation($payment, $due, Money::fromPaisa($part));
                    $this->dues->update($due, [
                        'paid_amount' => Money::fromPaisa($paid),
                        'status' => $paid >= Money::toPaisa($due->net_amount) ? FeeDue::STATUS_PAID : FeeDue::STATUS_PARTIAL,
                    ]);

                    $remaining -= $part;
                }

                return $payment;
            });
        } catch (UniqueConstraintViolationException $e) {
            if (UniqueViolation::is($e, 'fee_payments', ['method', 'transaction_id'], 'fee_payments_method_transaction_unique')) {
                throw $this->duplicateTransaction();
            }

            throw $e;
        }

        return $this->payments->loadReceipt($payment);
    }

    /**
     * Cancels a payment (admin only; the route requires delete-fees): reverses its
     * allocations so the dues owe what they did before it, and keeps the receipt number.
     */
    public function cancel(FeePayment $payment, string $reason, User $admin): FeePayment
    {
        $cancelled = DB::transaction(function () use ($payment, $reason, $admin) {
            // The student first, like collect(), so a cancellation and a collection for
            // the same student queue up.
            $this->payments->lockStudent($payment->student_id);
            $locked = $this->payments->lockPayment($payment);

            abort_if($locked->isCancelled(), 409, 'This payment has already been cancelled.');

            $locked = $this->payments->update($locked, [
                'cancelled_at' => Carbon::now('UTC'),
                'cancelled_by' => $admin->id,
                'cancel_reason' => $reason,
            ]);

            foreach ($this->payments->allocatedDues($locked) as $due) {
                $paid = array_sum(array_map(Money::toPaisa(...), $this->payments->activeAllocationAmounts($due)));

                $this->dues->update($due, [
                    'paid_amount' => Money::fromPaisa($paid),
                    'status' => $this->statusFor(Money::toPaisa($due->net_amount), $paid),
                ]);
            }

            return $locked;
        });

        return $this->payments->loadReceipt($cancelled);
    }

    private function statusFor(int $net, int $paid): string
    {
        return match (true) {
            $net === 0 => FeeDue::STATUS_WAIVED,
            $paid <= 0 => FeeDue::STATUS_UNPAID,
            $paid >= $net => FeeDue::STATUS_PAID,
            default => FeeDue::STATUS_PARTIAL,
        };
    }

    /**
     * The dues the money goes to: the chosen ones in that order (each must be the student's
     * and still open), otherwise every open due oldest first.
     *
     * @param  list<int>|null  $dueIds
     * @return \Illuminate\Support\Collection<int, FeeDue>
     */
    private function duesToPay(int $studentId, ?array $dueIds)
    {
        if ($dueIds === null || $dueIds === []) {
            return $this->dues->openForStudent($studentId);
        }

        $dueIds = array_values(array_unique(array_map('intval', $dueIds)));
        $dues = $this->dues->openByIdsForStudent($studentId, $dueIds);

        if ($dues->count() !== count($dueIds)) {
            throw ValidationException::withMessages(['due_ids' => ["Each due must be one of this student's dues that still owes money."]]);
        }

        return $dues;
    }

    private function normalizeTransactionId(?string $transactionId): ?string
    {
        $transactionId = $transactionId === null ? '' : strtoupper(trim($transactionId));

        return $transactionId === '' ? null : $transactionId;
    }

    private function paidAt(?string $paidAt, User $collector): Carbon
    {
        if (blank($paidAt)) {
            return Carbon::now('UTC');
        }

        if (! $this->users->hasRole($collector, 'admin')) {
            throw ValidationException::withMessages(['paid_at' => ['Only an admin can set the payment time.']]);
        }

        // An offset-less time is Asia/Dhaka wall-clock time (the school's local time).
        return Carbon::parse($paidAt, 'Asia/Dhaka')->utc();
    }

    private function duplicateTransaction(): ValidationException
    {
        return ValidationException::withMessages(['transaction_id' => ['This transaction ID has already been recorded for this method.']]);
    }
}
