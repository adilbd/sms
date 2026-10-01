<?php

namespace App\Repositories\Eloquent;

use App\Models\FeeDue;
use App\Models\FeePayment;
use App\Models\FeePaymentAllocation;
use App\Models\Student;
use App\Repositories\Contracts\FeePaymentRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class FeePaymentRepository extends EloquentRepository implements FeePaymentRepositoryInterface
{
    protected string $model = FeePayment::class;

    public function lockStudent(int $studentId): Student
    {
        return Student::query()->whereKey($studentId)->lockForUpdate()->firstOrFail();
    }

    public function lockPayment(FeePayment $payment): FeePayment
    {
        return FeePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
    }

    public function nextReceiptNumber(int $year): int
    {
        // Lock the row first. INSERT IGNORE on an existing row takes a shared lock on MySQL,
        // and two payments doing that and then SELECT ... FOR UPDATE would deadlock. Only
        // when the row is missing (first receipt of the year) is it inserted, and then
        // locked again; a concurrent insert is ignored.
        $counter = fn () => DB::table('fee_receipt_counters')->where('year', $year)->lockForUpdate()->value('last_number');

        $last = $counter();

        if ($last === null) {
            DB::table('fee_receipt_counters')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
            $last = $counter();
        }

        $last = (int) $last;
        $next = $last + 1;

        DB::table('fee_receipt_counters')->where('year', $year)->update(['last_number' => $next]);

        return $next;
    }

    public function transactionIdTaken(string $method, string $transactionId): bool
    {
        return FeePayment::query()->where('method', $method)->where('transaction_id', $transactionId)->exists();
    }

    public function addAllocation(FeePayment $payment, FeeDue $due, string $amount): void
    {
        FeePaymentAllocation::create([
            'fee_payment_id' => $payment->id,
            'fee_due_id' => $due->id,
            'amount' => $amount,
        ]);
    }

    public function activeAllocationAmounts(FeeDue $due): array
    {
        return FeePaymentAllocation::query()
            ->where('fee_due_id', $due->id)
            ->whereHas('payment', fn (Builder $q) => $q->whereNull('cancelled_at'))
            ->get()
            ->map(fn (FeePaymentAllocation $allocation) => $allocation->amount)
            ->all();
    }

    public function allocatedDues(FeePayment $payment): Collection
    {
        return FeeDue::query()
            ->whereIn('id', $payment->allocations()->select('fee_due_id'))
            ->orderBy('id')
            ->get();
    }

    public function loadReceipt(FeePayment $payment): FeePayment
    {
        return $payment->load([
            'student', 'collector', 'canceller',
            // In the order the money was applied.
            'allocations' => fn ($q) => $q->orderBy('id'),
            'allocations.due.head', 'allocations.due.enrolment.class', 'allocations.due.enrolment.section', 'allocations.due.enrolment.academicYear',
        ]);
    }

    protected function query(): Builder
    {
        return parent::query()->with(['student', 'collector'])->orderByDesc('paid_at')->orderByDesc('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach (['student_id', 'method', 'collected_by'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->where($column, $filters[$column]);
            }
        }

        if (filled($filters['from'] ?? null)) {
            $query->where('paid_at', '>=', $filters['from']);
        }

        if (filled($filters['to'] ?? null)) {
            $query->where('paid_at', '<', $filters['to']);
        }

        if (filled($filters['status'] ?? null)) {
            $filters['status'] === 'cancelled' ? $query->whereNotNull('cancelled_at') : $query->whereNull('cancelled_at');
        }

        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];
            $query->where(fn (Builder $q) => $q
                ->where('receipt_no', 'like', "%{$search}%")
                ->orWhere('transaction_id', 'like', "%{$search}%"));
        }

        return $query;
    }
}
