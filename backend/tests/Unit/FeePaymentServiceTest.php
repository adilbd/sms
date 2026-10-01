<?php

namespace Tests\Unit;

use App\Models\FeeDue;
use App\Models\FeePayment;
use App\Models\Student;
use App\Models\User;
use App\Repositories\Contracts\FeeDueRepositoryInterface;
use App\Repositories\Contracts\FeePaymentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\FeePaymentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * FeePaymentService against mocked repository interfaces, no database: the allocation
 * arithmetic, the rules that refuse a payment before anything is written, and the lock
 * order (student row, then the receipt counter; student, then payment for a cancellation).
 */
class FeePaymentServiceTest extends TestCase
{
    private function student(int $id = 7): Student
    {
        $student = new Student;
        $student->id = $id;

        return $student;
    }

    private function due(int $id, string $net, string $paid = '0.00', string $status = 'unpaid'): FeeDue
    {
        $due = new FeeDue(['net_amount' => $net, 'paid_amount' => $paid, 'status' => $status]);
        $due->id = $id;

        return $due;
    }

    private function payment(array $attributes = []): FeePayment
    {
        $payment = new FeePayment($attributes);
        $payment->id = 11;
        $payment->student_id = 7;

        return $payment;
    }

    private function user(int $id = 1): User
    {
        $user = new User;
        $user->id = $id;

        return $user;
    }

    private function roles(bool $admin): void
    {
        $this->mock(UserRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('hasRole')->andReturn($admin));
    }

    /** @return array<string, list<string>> */
    private function errors(callable $call): array
    {
        try {
            $call();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            return $e->errors();
        }
    }

    private function untouched(): void
    {
        $this->mock(FeePaymentRepositoryInterface::class, function (MockInterface $m) {
            $m->shouldNotReceive('lockStudent', 'nextReceiptNumber', 'create', 'addAllocation');
        });
        $this->mock(FeeDueRepositoryInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('update'));
    }

    public function test_it_locks_the_student_then_reads_the_dues_then_the_counter_then_writes(): void
    {
        $this->travelTo('2026-10-15 06:00:00');
        $this->roles(false);
        $due = $this->due(1, '800.00');
        $payments = $this->mock(FeePaymentRepositoryInterface::class);
        $dues = $this->mock(FeeDueRepositoryInterface::class);

        // Declared in the order the service must call them (Mockery orders globally).
        $payments->shouldReceive('transactionIdTaken')->never();
        $payments->shouldReceive('lockStudent')->once()->with(7)->globally()->ordered()->andReturn($this->student());
        $dues->shouldReceive('openForStudent')->once()->with(7)->globally()->ordered()->andReturn(new Collection([$due]));
        $payments->shouldReceive('nextReceiptNumber')->once()->with(2026)->globally()->ordered()->andReturn(41);
        $payments->shouldReceive('create')->once()->globally()->ordered()
            ->withArgs(fn (array $a) => $a['receipt_no'] === '2026-000041' && $a['student_id'] === 7 && $a['amount'] === '800.00' && $a['transaction_id'] === null && $a['collected_by'] === 1)
            ->andReturn($this->payment());
        $payments->shouldReceive('addAllocation')->once()->globally()->ordered()->with(\Mockery::type(FeePayment::class), $due, '800.00');
        $dues->shouldReceive('update')->once()->with($due, ['paid_amount' => '800.00', 'status' => 'paid'])->globally()->ordered();
        $payments->shouldReceive('loadReceipt')->once()->andReturn($this->payment());

        app(FeePaymentService::class)->collect(['student_id' => 7, 'amount' => '800', 'method' => 'cash'], $this->user());
    }

    public function test_it_allocates_oldest_first_and_leaves_the_last_due_partial(): void
    {
        $this->roles(false);
        [$first, $second, $third] = [$this->due(1, '800.00'), $this->due(2, '800.00'), $this->due(3, '800.00')];

        $this->mock(FeePaymentRepositoryInterface::class, function (MockInterface $m) use ($first, $second) {
            $m->shouldReceive('lockStudent')->andReturn($this->student());
            $m->shouldReceive('nextReceiptNumber')->andReturn(1);
            $m->shouldReceive('create')->andReturn($this->payment());
            $m->shouldReceive('addAllocation')->once()->with(\Mockery::any(), $first, '800.00')->ordered();
            $m->shouldReceive('addAllocation')->once()->with(\Mockery::any(), $second, '200.00')->ordered();
            $m->shouldReceive('loadReceipt')->andReturn($this->payment());
        });
        $this->mock(FeeDueRepositoryInterface::class, function (MockInterface $m) use ($first, $second, $third) {
            $m->shouldReceive('openForStudent')->andReturn(new Collection([$first, $second, $third]));
            $m->shouldReceive('update')->once()->with($first, ['paid_amount' => '800.00', 'status' => 'paid']);
            $m->shouldReceive('update')->once()->with($second, ['paid_amount' => '200.00', 'status' => 'partial']);
            // The third due is not touched.
        });

        app(FeePaymentService::class)->collect(['student_id' => 7, 'amount' => '1000.00', 'method' => 'cash'], $this->user());
    }

    public function test_a_second_part_payment_adds_to_what_was_already_paid(): void
    {
        $this->roles(false);
        $due = $this->due(1, '800.00', '200.00', 'partial');

        $this->mock(FeePaymentRepositoryInterface::class, function (MockInterface $m) use ($due) {
            $m->shouldReceive('lockStudent')->andReturn($this->student());
            $m->shouldReceive('nextReceiptNumber')->andReturn(2);
            $m->shouldReceive('create')->andReturn($this->payment());
            $m->shouldReceive('addAllocation')->once()->with(\Mockery::any(), $due, '600.00');
            $m->shouldReceive('loadReceipt')->andReturn($this->payment());
        });
        $this->mock(FeeDueRepositoryInterface::class, function (MockInterface $m) use ($due) {
            $m->shouldReceive('openForStudent')->andReturn(new Collection([$due]));
            $m->shouldReceive('update')->once()->with($due, ['paid_amount' => '800.00', 'status' => 'paid']);
        });

        app(FeePaymentService::class)->collect(['student_id' => 7, 'amount' => '600', 'method' => 'cash'], $this->user());
    }

    public function test_chosen_dues_are_paid_in_the_order_given(): void
    {
        $this->roles(false);
        [$a, $b] = [$this->due(1, '800.00'), $this->due(2, '800.00')];

        $this->mock(FeePaymentRepositoryInterface::class, function (MockInterface $m) use ($a, $b) {
            $m->shouldReceive('lockStudent')->andReturn($this->student());
            $m->shouldReceive('nextReceiptNumber')->andReturn(1);
            $m->shouldReceive('create')->andReturn($this->payment());
            $m->shouldReceive('addAllocation')->once()->with(\Mockery::any(), $b, '800.00')->ordered();
            $m->shouldReceive('addAllocation')->once()->with(\Mockery::any(), $a, '100.00')->ordered();
            $m->shouldReceive('loadReceipt')->andReturn($this->payment());
        });
        $this->mock(FeeDueRepositoryInterface::class, function (MockInterface $m) use ($a, $b) {
            $m->shouldReceive('openByIdsForStudent')->once()->with(7, [2, 1])->andReturn(new Collection([$b, $a]));
            $m->shouldReceive('openForStudent')->never();
            $m->shouldReceive('update')->twice();
        });

        app(FeePaymentService::class)->collect(['student_id' => 7, 'amount' => '900', 'method' => 'cash', 'due_ids' => [2, 1]], $this->user());
    }

    public function test_a_due_id_that_is_not_an_open_due_of_the_student_is_refused(): void
    {
        $this->roles(false);
        $this->mock(FeePaymentRepositoryInterface::class, function (MockInterface $m) {
            $m->shouldReceive('lockStudent')->andReturn($this->student());
            $m->shouldNotReceive('nextReceiptNumber', 'create', 'addAllocation');
        });
        $this->mock(FeeDueRepositoryInterface::class, function (MockInterface $m) {
            $m->shouldReceive('openByIdsForStudent')->andReturn(new Collection([$this->due(1, '800.00')]));
            $m->shouldNotReceive('update');
        });

        $errors = $this->errors(fn () => app(FeePaymentService::class)->collect(['student_id' => 7, 'amount' => '100', 'method' => 'cash', 'due_ids' => [1, 99]], $this->user()));

        $this->assertArrayHasKey('due_ids', $errors);
    }

    public function test_an_amount_above_the_outstanding_total_is_refused_before_a_receipt_number_is_taken(): void
    {
        $this->roles(false);
        $this->mock(FeePaymentRepositoryInterface::class, function (MockInterface $m) {
            $m->shouldReceive('lockStudent')->once()->andReturn($this->student());
            $m->shouldNotReceive('nextReceiptNumber', 'create', 'addAllocation');
        });
        $this->mock(FeeDueRepositoryInterface::class, function (MockInterface $m) {
            $m->shouldReceive('openForStudent')->andReturn(new Collection([$this->due(1, '800.00', '300.00', 'partial')]));
            $m->shouldNotReceive('update');
        });

        $errors = $this->errors(fn () => app(FeePaymentService::class)->collect(['student_id' => 7, 'amount' => '500.01', 'method' => 'cash'], $this->user()));

        $this->assertStringContainsString('500.00', $errors['amount'][0]);
    }

    public function test_an_amount_that_is_not_positive_is_refused_without_locking_anything(): void
    {
        $this->roles(false);
        $this->untouched();

        foreach (['0', '0.00', '-5'] as $amount) {
            $errors = $this->errors(fn () => app(FeePaymentService::class)->collect(['student_id' => 7, 'amount' => $amount, 'method' => 'cash'], $this->user()));
            $this->assertArrayHasKey('amount', $errors);
        }
    }

    public function test_a_mobile_method_needs_a_transaction_id(): void
    {
        $this->roles(false);
        $this->untouched();

        foreach (['bkash', 'nagad', 'rocket'] as $method) {
            foreach ([null, '', '   '] as $transactionId) {
                $errors = $this->errors(fn () => app(FeePaymentService::class)->collect(['student_id' => 7, 'amount' => '100', 'method' => $method, 'transaction_id' => $transactionId], $this->user()));
                $this->assertArrayHasKey('transaction_id', $errors);
            }
        }
    }

    public function test_a_transaction_id_is_trimmed_and_uppercased_before_it_is_checked_and_stored(): void
    {
        $this->roles(false);
        $due = $this->due(1, '800.00');
        $this->mock(FeePaymentRepositoryInterface::class, function (MockInterface $m) {
            $m->shouldReceive('transactionIdTaken')->once()->with('bkash', '8N7A6B5C')->andReturn(false);
            $m->shouldReceive('lockStudent')->andReturn($this->student());
            $m->shouldReceive('nextReceiptNumber')->andReturn(1);
            $m->shouldReceive('create')->once()->withArgs(fn (array $a) => $a['transaction_id'] === '8N7A6B5C' && $a['method'] === 'bkash')->andReturn($this->payment());
            $m->shouldReceive('addAllocation');
            $m->shouldReceive('loadReceipt')->andReturn($this->payment());
        });
        $this->mock(FeeDueRepositoryInterface::class, function (MockInterface $m) use ($due) {
            $m->shouldReceive('openForStudent')->andReturn(new Collection([$due]));
            $m->shouldReceive('update');
        });

        app(FeePaymentService::class)->collect(['student_id' => 7, 'amount' => '100', 'method' => 'bkash', 'transaction_id' => ' 8n7a6b5c '], $this->user());
    }

    public function test_a_duplicate_transaction_id_is_refused_before_locking(): void
    {
        $this->roles(false);
        $this->mock(FeePaymentRepositoryInterface::class, function (MockInterface $m) {
            $m->shouldReceive('transactionIdTaken')->once()->with('bkash', 'TX1')->andReturn(true);
            $m->shouldNotReceive('lockStudent', 'nextReceiptNumber', 'create');
        });
        $this->mock(FeeDueRepositoryInterface::class);

        $errors = $this->errors(fn () => app(FeePaymentService::class)->collect(['student_id' => 7, 'amount' => '100', 'method' => 'bkash', 'transaction_id' => 'tx1'], $this->user()));

        $this->assertArrayHasKey('transaction_id', $errors);
    }

    public function test_only_an_admin_may_set_paid_at(): void
    {
        $this->roles(false);
        $this->untouched();

        $errors = $this->errors(fn () => app(FeePaymentService::class)->collect(['student_id' => 7, 'amount' => '100', 'method' => 'cash', 'paid_at' => '2026-10-01T10:00:00+06:00'], $this->user()));

        $this->assertArrayHasKey('paid_at', $errors);
    }

    public function test_an_admins_paid_at_is_stored_in_utc(): void
    {
        $this->roles(true);
        $due = $this->due(1, '800.00');
        $this->mock(FeePaymentRepositoryInterface::class, function (MockInterface $m) {
            $m->shouldReceive('lockStudent')->andReturn($this->student());
            $m->shouldReceive('nextReceiptNumber')->andReturn(1);
            $m->shouldReceive('create')->once()->withArgs(fn (array $a) => $a['paid_at']->toIso8601String() === '2026-10-01T04:00:00+00:00')->andReturn($this->payment());
            $m->shouldReceive('addAllocation');
            $m->shouldReceive('loadReceipt')->andReturn($this->payment());
        });
        $this->mock(FeeDueRepositoryInterface::class, function (MockInterface $m) use ($due) {
            $m->shouldReceive('openForStudent')->andReturn(new Collection([$due]));
            $m->shouldReceive('update');
        });

        app(FeePaymentService::class)->collect(['student_id' => 7, 'amount' => '100', 'method' => 'cash', 'paid_at' => '2026-10-01T10:00:00+06:00'], $this->user());
    }

    public function test_the_receipt_year_is_the_asia_dhaka_year(): void
    {
        // 20:00 UTC on New Year's Eve is already January 1st in Dhaka.
        $this->travelTo('2026-12-31 20:00:00');
        $this->roles(false);
        $due = $this->due(1, '800.00');
        $this->mock(FeePaymentRepositoryInterface::class, function (MockInterface $m) {
            $m->shouldReceive('lockStudent')->andReturn($this->student());
            $m->shouldReceive('nextReceiptNumber')->once()->with(2027)->andReturn(3);
            $m->shouldReceive('create')->once()->withArgs(fn (array $a) => $a['receipt_no'] === '2027-000003')->andReturn($this->payment());
            $m->shouldReceive('addAllocation');
            $m->shouldReceive('loadReceipt')->andReturn($this->payment());
        });
        $this->mock(FeeDueRepositoryInterface::class, function (MockInterface $m) use ($due) {
            $m->shouldReceive('openForStudent')->andReturn(new Collection([$due]));
            $m->shouldReceive('update');
        });

        app(FeePaymentService::class)->collect(['student_id' => 7, 'amount' => '100', 'method' => 'cash'], $this->user());
    }

    public function test_cancel_locks_the_student_then_the_payment_then_recomputes_each_due(): void
    {
        $payment = $this->payment();
        $locked = $this->payment();
        $due = $this->due(1, '800.00', '800.00', 'paid');

        $this->mock(FeePaymentRepositoryInterface::class, function (MockInterface $m) use ($payment, $locked, $due) {
            $m->shouldReceive('lockStudent')->once()->with(7)->globally()->ordered()->andReturn($this->student());
            $m->shouldReceive('lockPayment')->once()->with($payment)->globally()->ordered()->andReturn($locked);
            $m->shouldReceive('update')->once()->globally()->ordered()
                ->withArgs(fn (FeePayment $p, array $a) => $p === $locked && $a['cancelled_by'] === 9 && $a['cancel_reason'] === 'Wrong student' && $a['cancelled_at'] !== null)
                ->andReturn($locked);
            $m->shouldReceive('allocatedDues')->once()->with($locked)->globally()->ordered()->andReturn(new Collection([$due]));
            // 300.00 is still allocated by another payment, so the due goes back to partial.
            $m->shouldReceive('activeAllocationAmounts')->once()->with($due)->globally()->ordered()->andReturn(['300.00']);
            $m->shouldReceive('loadReceipt')->once()->andReturn($locked);
        });
        $this->mock(FeeDueRepositoryInterface::class, function (MockInterface $m) use ($due) {
            $m->shouldReceive('update')->once()->with($due, ['paid_amount' => '300.00', 'status' => 'partial'])->globally()->ordered();
        });
        $this->roles(true);

        app(FeePaymentService::class)->cancel($payment, 'Wrong student', $this->user(9));
    }

    public function test_cancel_puts_a_due_with_no_other_allocation_back_to_unpaid(): void
    {
        $payment = $this->payment();
        $due = $this->due(1, '800.00', '800.00', 'paid');

        $this->mock(FeePaymentRepositoryInterface::class, function (MockInterface $m) use ($payment, $due) {
            $m->shouldReceive('lockStudent')->andReturn($this->student());
            $m->shouldReceive('lockPayment')->andReturn($payment);
            $m->shouldReceive('update')->andReturn($payment);
            $m->shouldReceive('allocatedDues')->andReturn(new Collection([$due]));
            $m->shouldReceive('activeAllocationAmounts')->andReturn([]);
            $m->shouldReceive('loadReceipt')->andReturn($payment);
        });
        $this->mock(FeeDueRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('update')->once()->with($due, ['paid_amount' => '0.00', 'status' => 'unpaid']));
        $this->roles(true);

        app(FeePaymentService::class)->cancel($payment, 'x', $this->user(9));
    }

    public function test_cancelling_a_cancelled_payment_is_a_conflict_and_changes_nothing(): void
    {
        $payment = $this->payment(['cancelled_at' => '2026-10-01 00:00:00']);

        $this->mock(FeePaymentRepositoryInterface::class, function (MockInterface $m) use ($payment) {
            $m->shouldReceive('lockStudent')->once()->andReturn($this->student());
            $m->shouldReceive('lockPayment')->once()->andReturn($payment);
            $m->shouldNotReceive('update', 'allocatedDues');
        });
        $this->mock(FeeDueRepositoryInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('update'));
        $this->roles(true);

        try {
            app(FeePaymentService::class)->cancel($payment, 'x', $this->user(9));
            $this->fail('Expected a 409.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_collect_is_retried_when_the_first_attempt_deadlocks(): void
    {
        $this->roles(false);
        $due = $this->due(1, '800.00');
        $payments = $this->mock(FeePaymentRepositoryInterface::class);
        $dues = $this->mock(FeeDueRepositoryInterface::class);

        $attempts = 0;
        $payments->shouldReceive('lockStudent')->twice()->andReturnUsing(function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new QueryException('sqlite', 'select ... for update', [], new PDOException('Deadlock found when trying to get lock; try restarting transaction'));
            }

            return $this->student();
        });
        $dues->shouldReceive('openForStudent')->once()->andReturn(new Collection([$due]));
        $payments->shouldReceive('nextReceiptNumber')->once()->andReturn(1);
        $payments->shouldReceive('create')->once()->andReturn($this->payment());
        $payments->shouldReceive('addAllocation')->once();
        $dues->shouldReceive('update')->once();
        $payments->shouldReceive('loadReceipt')->once()->andReturn($this->payment());

        app(FeePaymentService::class)->collect(['student_id' => 7, 'amount' => '800', 'method' => 'cash'], $this->user());

        $this->assertSame(2, $attempts);
    }
}
