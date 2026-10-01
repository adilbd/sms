<?php

namespace App\Repositories\Contracts;

use App\Models\FeeDue;
use App\Models\FeePayment;
use App\Models\Student;

interface FeePaymentRepositoryInterface extends RepositoryInterface
{
    /**
     * Takes a row lock on the student (`select ... for update`) and returns the freshly
     * read row, so collecting and cancelling for one student run one after another and each
     * sees the other's committed dues. Call inside a transaction. A soft-deleted student is
     * a 404.
     *
     * Lock order for the fee code: the student row first, then the receipt counter row (a
     * collection) or the payment row (a cancellation), never the other way round.
     */
    public function lockStudent(int $studentId): Student;

    /**
     * Takes a row lock on the payment and returns the freshly read row. Call inside a
     * transaction, after lockStudent().
     */
    public function lockPayment(FeePayment $payment): FeePayment;

    /**
     * The next receipt number (1, 2, ...) of the calendar year, taken from a per-year
     * counter row that is locked `for update`, so two payments never get the same number.
     * Call inside a transaction, after lockStudent(); a rollback gives the number back.
     */
    public function nextReceiptNumber(int $year): int;

    /**
     * Whether a payment (cancelled or not) already carries this transaction ID for the method.
     */
    public function transactionIdTaken(string $method, string $transactionId): bool;

    public function addAllocation(FeePayment $payment, FeeDue $due, string $amount): void;

    /**
     * The amounts (`decimal:2` strings) allocated to the due by payments that are not cancelled.
     *
     * @return list<string>
     */
    public function activeAllocationAmounts(FeeDue $due): array;

    /**
     * The dues the payment was allocated to, with their head, for reversing it.
     */
    public function allocatedDues(FeePayment $payment): \Illuminate\Database\Eloquent\Collection;

    /**
     * Loads what a receipt shows: student, collector, canceller, and each allocation's due
     * with its head and enrolment (class, section, year).
     */
    public function loadReceipt(FeePayment $payment): FeePayment;
}
