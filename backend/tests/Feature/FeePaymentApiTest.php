<?php

namespace Tests\Feature;

use App\Models\FeeDue;
use App\Models\FeePayment;
use App\Models\StudentEnrolment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsFees;
use Tests\TestCase;

class FeePaymentApiTest extends TestCase
{
    use BuildsFees, RefreshDatabase;

    private StudentEnrolment $enrolment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFees();
        [$this->enrolment] = $this->enrolStudents(1);
    }

    /** September and October dues of ৳800 for the one student. */
    private function twoDues(): array
    {
        $this->generateDues(['month' => '2026-09'])->assertOk();
        $this->generateDues(['month' => '2026-10'])->assertOk();

        return [
            FeeDue::where('period', '2026-09')->firstOrFail(),
            FeeDue::where('period', '2026-10')->firstOrFail(),
        ];
    }

    public function test_a_payment_is_allocated_oldest_first_with_a_partial_second_due(): void
    {
        [$sep, $oct] = $this->twoDues();

        $this->pay($this->enrolment, '1000.00')
            ->assertCreated()
            ->assertJsonPath('message', 'Payment recorded successfully')
            ->assertJsonPath('data.receipt_no', '2026-000001')
            ->assertJsonPath('data.amount', '1000.00')
            ->assertJsonPath('data.method', 'cash')
            ->assertJsonPath('data.is_cancelled', false)
            ->assertJsonPath('data.collected_by', $this->office->id)
            ->assertJsonCount(2, 'data.allocations')
            ->assertJsonPath('data.allocations.0.fee_due_id', $sep->id)
            ->assertJsonPath('data.allocations.0.amount', '800.00')
            ->assertJsonPath('data.allocations.1.amount', '200.00')
            ->assertJsonStructure(['data' => ['id', 'paid_at', 'student', 'allocations' => [['due' => ['head', 'enrolment' => ['class', 'section']]]]]]);

        $this->assertSame(['paid', '800.00'], [$sep->fresh()->status, $sep->fresh()->paid_amount]);
        $this->assertSame(['partial', '200.00'], [$oct->fresh()->status, $oct->fresh()->paid_amount]);
    }

    public function test_part_payments_accumulate_until_the_due_is_paid(): void
    {
        [, $oct] = $this->twoDues();
        $oct->update(['status' => 'paid', 'paid_amount' => '800.00']);
        FeeDue::where('period', '2026-09')->update(['status' => 'paid', 'paid_amount' => '800.00']);
        $this->generateDues(['month' => '2026-11'])->assertOk();
        $nov = FeeDue::where('period', '2026-11')->firstOrFail();

        $this->pay($this->enrolment, '300.50')->assertCreated();
        $this->assertSame(['partial', '300.50'], [$nov->fresh()->status, $nov->fresh()->paid_amount]);

        $this->pay($this->enrolment, '499.50')->assertCreated()->assertJsonPath('data.receipt_no', '2026-000002');
        $this->assertSame(['paid', '800.00'], [$nov->fresh()->status, $nov->fresh()->paid_amount]);

        $this->pay($this->enrolment, '1.00')->assertUnprocessable()->assertJsonValidationErrors(['amount']);
    }

    public function test_a_mobile_payment_needs_a_transaction_id_and_gets_the_next_receipt_number(): void
    {
        $this->twoDues();
        $this->pay($this->enrolment, '1000.00')->assertCreated();

        $this->pay($this->enrolment, '300.00', 'bkash', ['transaction_id' => ' 8n7a6b5c '])
            ->assertCreated()
            ->assertJsonPath('data.receipt_no', '2026-000002')
            ->assertJsonPath('data.method', 'bkash')
            ->assertJsonPath('data.transaction_id', '8N7A6B5C');

        $this->assertDatabaseHas('fee_payments', ['method' => 'bkash', 'transaction_id' => '8N7A6B5C']);
    }

    public function test_cash_ignores_a_transaction_id(): void
    {
        $this->twoDues();

        $this->pay($this->enrolment, '100.00', 'cash', ['transaction_id' => 'ABC'])
            ->assertCreated()
            ->assertJsonPath('data.transaction_id', null);
    }

    public function test_validation_failures_are_422_and_change_nothing(): void
    {
        $this->twoDues();
        $before = FeeDue::pluck('paid_amount', 'id')->all();

        // More than the 1600.00 outstanding.
        $this->pay($this->enrolment, '1600.01')->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->pay($this->enrolment, '0')->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->pay($this->enrolment, '0.00')->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->pay($this->enrolment, '-5')->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->pay($this->enrolment, '10.505')->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->pay($this->enrolment, 'abc')->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->pay($this->enrolment, '100', 'cheque')->assertUnprocessable()->assertJsonValidationErrors(['method']);
        $this->pay($this->enrolment, '100', 'bkash')->assertUnprocessable()->assertJsonValidationErrors(['transaction_id']);
        $this->pay($this->enrolment, '100', 'nagad', ['transaction_id' => '  '])->assertUnprocessable()->assertJsonValidationErrors(['transaction_id']);
        $this->pay(999999, '100')->assertUnprocessable()->assertJsonValidationErrors(['student_id']);
        $this->as($this->office)->postJson('/api/fee-payments', [])->assertUnprocessable()->assertJsonValidationErrors(['student_id', 'amount', 'method']);

        $this->assertSame(0, FeePayment::count());
        $this->assertSame($before, FeeDue::pluck('paid_amount', 'id')->all());
        $this->assertSame(0, DB::table('fee_receipt_counters')->count());
    }

    public function test_a_transaction_id_is_unique_per_method(): void
    {
        $this->twoDues();
        $this->pay($this->enrolment, '100.00', 'bkash', ['transaction_id' => '8N7A6B5C'])->assertCreated();

        $this->pay($this->enrolment, '100.00', 'bkash', ['transaction_id' => '8n7a6b5c'])
            ->assertUnprocessable()->assertJsonValidationErrors(['transaction_id']);
        // Another method may use the same code.
        $this->pay($this->enrolment, '100.00', 'nagad', ['transaction_id' => '8N7A6B5C'])->assertCreated();
        $this->assertSame(2, FeePayment::count());
    }

    public function test_a_cancelled_payments_transaction_id_stays_reserved(): void
    {
        $this->twoDues();
        $id = $this->pay($this->enrolment, '100.00', 'rocket', ['transaction_id' => 'R1'])->assertCreated()->json('data.id');
        $this->as($this->admin)->postJson("/api/fee-payments/{$id}/cancel", ['reason' => 'Wrong student'])->assertOk();

        $this->pay($this->enrolment, '100.00', 'rocket', ['transaction_id' => 'R1'])
            ->assertUnprocessable()->assertJsonValidationErrors(['transaction_id']);
    }

    public function test_specific_dues_can_be_chosen_and_are_paid_in_that_order(): void
    {
        [$sep, $oct] = $this->twoDues();

        $this->pay($this->enrolment, '900.00', 'cash', ['due_ids' => [$oct->id, $sep->id]])
            ->assertCreated()
            ->assertJsonPath('data.allocations.0.fee_due_id', $oct->id);

        $this->assertSame('800.00', $oct->fresh()->paid_amount);
        $this->assertSame('100.00', $sep->fresh()->paid_amount);

        // Only the chosen dues count as outstanding: 700.00 is left on September.
        $this->pay($this->enrolment, '700.01', 'cash', ['due_ids' => [$sep->id]])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->pay($this->enrolment, '700.00', 'cash', ['due_ids' => [$sep->id]])->assertCreated();
    }

    public function test_due_ids_must_be_the_students_open_dues(): void
    {
        [$sep] = $this->twoDues();
        [, $other] = $this->enrolStudents(2);
        $this->generateDues(['month' => '2026-10'])->assertOk();
        $others = FeeDue::where('enrolment_id', $other->id)->firstOrFail();
        $sep->update(['status' => 'paid', 'paid_amount' => '800.00']);

        $this->pay($this->enrolment, '100.00', 'cash', ['due_ids' => [$others->id]])->assertUnprocessable()->assertJsonValidationErrors(['due_ids']);
        $this->pay($this->enrolment, '100.00', 'cash', ['due_ids' => [$sep->id]])->assertUnprocessable()->assertJsonValidationErrors(['due_ids']);
        $this->pay($this->enrolment, '100.00', 'cash', ['due_ids' => [999999]])->assertUnprocessable()->assertJsonValidationErrors(['due_ids']);
        $this->pay($this->enrolment, '100.00', 'cash', ['due_ids' => [1, 1]])->assertUnprocessable()->assertJsonValidationErrors(['due_ids.0']);
    }

    public function test_malformed_payment_amounts_are_422_not_500(): void
    {
        $this->twoDues();

        foreach (['+5', '.5', '5.', '1e2'] as $bad) {
            $this->pay($this->enrolment, $bad)->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        }
    }

    public function test_an_offset_less_paid_at_is_asia_dhaka_time(): void
    {
        $this->twoDues();

        $this->as($this->admin)->postJson('/api/fee-payments', [
            'student_id' => $this->enrolment->student_id, 'amount' => '100.00', 'method' => 'cash', 'paid_at' => '2026-10-01 10:00:00',
        ])->assertCreated()->assertJsonPath('data.paid_at', '2026-10-01T04:00:00+00:00');
    }

    public function test_the_first_payment_of_a_year_creates_the_counter_row_and_later_ones_reuse_it(): void
    {
        $this->twoDues();
        $this->assertDatabaseMissing('fee_receipt_counters', ['year' => 2026]);

        $this->pay($this->enrolment, '100.00')->assertCreated()->assertJsonPath('data.receipt_no', '2026-000001');
        $this->assertDatabaseHas('fee_receipt_counters', ['year' => 2026, 'last_number' => 1]);

        $this->pay($this->enrolment, '100.00')->assertCreated()->assertJsonPath('data.receipt_no', '2026-000002');
        $this->assertDatabaseHas('fee_receipt_counters', ['year' => 2026, 'last_number' => 2]);
    }

    public function test_an_existing_counter_row_is_locked_before_any_insert_is_attempted(): void
    {
        DB::table('fee_receipt_counters')->insert(['year' => 2026, 'last_number' => 7]);

        DB::enableQueryLog();
        $number = app(\App\Repositories\Contracts\FeePaymentRepositoryInterface::class)->nextReceiptNumber(2026);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertSame(8, $number);
        $this->assertStringStartsWith('select', $queries->first());
        $this->assertTrue($queries->doesntContain(fn (string $q) => str_starts_with($q, 'insert')), 'No insert may run when the row exists.');
    }

    public function test_only_an_admin_can_backdate_a_payment(): void
    {
        $this->twoDues();

        $this->pay($this->enrolment, '100.00', 'cash', ['paid_at' => '2026-10-01T10:00:00+06:00'])
            ->assertUnprocessable()->assertJsonValidationErrors(['paid_at']);

        $this->as($this->admin)->postJson('/api/fee-payments', [
            'student_id' => $this->enrolment->student_id, 'amount' => '100.00', 'method' => 'cash', 'paid_at' => '2026-10-01T10:00:00+06:00',
        ])->assertCreated()->assertJsonPath('data.paid_at', '2026-10-01T04:00:00+00:00');

        $this->as($this->admin)->postJson('/api/fee-payments', [
            'student_id' => $this->enrolment->student_id, 'amount' => '100.00', 'method' => 'cash', 'paid_at' => '2027-01-01T10:00:00+06:00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['paid_at']);
    }

    public function test_paid_at_defaults_to_now_in_utc(): void
    {
        $this->twoDues();

        $this->pay($this->enrolment, '100.00')->assertCreated()->assertJsonPath('data.paid_at', '2026-10-15T06:00:00+00:00');
    }

    public function test_receipt_numbers_are_sequential_per_calendar_year_in_dhaka(): void
    {
        [$a, $b] = [$this->enrolment, $this->enrolStudents(1)[0]];
        $this->generateDues(['month' => '2026-10'])->assertOk();

        $this->pay($a, '100.00')->assertCreated()->assertJsonPath('data.receipt_no', '2026-000001');
        $this->pay($b, '100.00')->assertCreated()->assertJsonPath('data.receipt_no', '2026-000002');
        $this->pay($a, '100.00')->assertCreated()->assertJsonPath('data.receipt_no', '2026-000003');

        // 2026-12-31 20:00 UTC is already January 1st in Dhaka: a new year, a new sequence.
        $this->travelTo('2026-12-31 20:00:00');
        $this->pay($a, '100.00')->assertCreated()->assertJsonPath('data.receipt_no', '2027-000001');
    }

    public function test_the_counter_continues_from_its_saved_value_and_rolls_back_with_a_failed_payment(): void
    {
        $this->twoDues();
        DB::table('fee_receipt_counters')->insert(['year' => 2026, 'last_number' => 41]);

        $this->pay($this->enrolment, '100.00')->assertCreated()->assertJsonPath('data.receipt_no', '2026-000042');

        // A refused payment does not use up a number.
        $this->pay($this->enrolment, '999999.00')->assertUnprocessable();
        $this->pay($this->enrolment, '100.00')->assertCreated()->assertJsonPath('data.receipt_no', '2026-000043');
        $this->assertSame(43, (int) DB::table('fee_receipt_counters')->where('year', 2026)->value('last_number'));
    }

    public function test_a_student_without_dues_cannot_pay(): void
    {
        $this->pay($this->enrolment, '100.00')->assertUnprocessable()->assertJsonValidationErrors(['amount']);
    }

    public function test_cancelling_reverses_the_allocations_and_keeps_the_receipt_number(): void
    {
        [$sep, $oct] = $this->twoDues();
        $first = $this->pay($this->enrolment, '1000.00')->assertCreated()->json('data.id');
        $second = $this->pay($this->enrolment, '300.00', 'bkash', ['transaction_id' => '8N7A6B5C'])->assertCreated()->json('data.id');
        // 1000 paid September (800) and 200 of October; the 300 brings October to 500.
        $this->assertSame('500.00', $oct->fresh()->paid_amount);

        $this->as($this->admin)->postJson("/api/fee-payments/{$first}/cancel", ['reason' => 'Entered twice'])
            ->assertOk()
            ->assertJsonPath('message', 'Payment cancelled successfully')
            ->assertJsonPath('data.is_cancelled', true)
            ->assertJsonPath('data.cancel_reason', 'Entered twice')
            ->assertJsonPath('data.cancelled_by', $this->admin->id)
            ->assertJsonPath('data.receipt_no', '2026-000001');

        // September is back to unpaid; October keeps what the second payment gave it.
        $this->assertSame(['unpaid', '0.00'], [$sep->fresh()->status, $sep->fresh()->paid_amount]);
        $this->assertSame(['partial', '300.00'], [$oct->fresh()->status, $oct->fresh()->paid_amount]);

        $this->as($this->admin)->postJson("/api/fee-payments/{$second}/cancel", ['reason' => 'Wrong amount'])->assertOk();
        $this->assertSame(['unpaid', '0.00'], [$oct->fresh()->status, $oct->fresh()->paid_amount]);

        // The cancelled receipt numbers are never reissued.
        $this->pay($this->enrolment, '100.00')->assertCreated()->assertJsonPath('data.receipt_no', '2026-000003');
        // The allocation rows stay as history: 2 + 1 for the cancelled payments, 1 for the new one.
        $this->assertSame(4, DB::table('fee_payment_allocations')->count());
    }

    public function test_cancelling_twice_is_a_conflict(): void
    {
        $this->twoDues();
        $id = $this->pay($this->enrolment, '100.00')->assertCreated()->json('data.id');

        $this->as($this->admin)->postJson("/api/fee-payments/{$id}/cancel", ['reason' => 'x'])->assertOk();
        $this->as($this->admin)->postJson("/api/fee-payments/{$id}/cancel", ['reason' => 'x'])->assertStatus(409);
    }

    public function test_cancelling_needs_a_reason_and_an_existing_payment(): void
    {
        $this->twoDues();
        $id = $this->pay($this->enrolment, '100.00')->assertCreated()->json('data.id');

        $this->as($this->admin)->postJson("/api/fee-payments/{$id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->as($this->admin)->postJson("/api/fee-payments/{$id}/cancel", ['reason' => str_repeat('x', 501)])->assertUnprocessable();
        $this->as($this->admin)->postJson('/api/fee-payments/999999/cancel', ['reason' => 'x'])->assertNotFound();
        $this->as($this->admin)->postJson('/api/fee-payments/1abc/cancel', ['reason' => 'x'])->assertNotFound();
        $this->assertNull(FeePayment::findOrFail($id)->cancelled_at);
    }

    public function test_a_cancelled_payment_frees_the_outstanding_amount_again(): void
    {
        [$sep, $oct] = $this->twoDues();
        $id = $this->pay($this->enrolment, '1600.00')->assertCreated()->json('data.id');
        $this->pay($this->enrolment, '1.00')->assertUnprocessable();

        $this->as($this->admin)->postJson("/api/fee-payments/{$id}/cancel", ['reason' => 'x'])->assertOk();

        $this->pay($this->enrolment, '1600.00')->assertCreated();
        $this->assertSame(['paid', 'paid'], [$sep->fresh()->status, $oct->fresh()->status]);
    }

    public function test_the_list_and_receipt_endpoints(): void
    {
        $this->twoDues();
        $cash = $this->pay($this->enrolment, '1000.00')->assertCreated()->json('data.id');
        $bkash = $this->pay($this->enrolment, '200.00', 'bkash', ['transaction_id' => 'TX1'])->assertCreated()->json('data.id');
        $this->as($this->admin)->postJson("/api/fee-payments/{$bkash}/cancel", ['reason' => 'x'])->assertOk();

        $this->as($this->office)->getJson('/api/fee-payments')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'receipt_no', 'student_id', 'paid_at', 'method', 'transaction_id', 'amount', 'collected_by', 'collected_by_name', 'is_cancelled', 'student']], 'links', 'meta' => ['total']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonMissingPath('data.0.allocations');

        $this->as($this->office)->getJson('/api/fee-payments?method=bkash')->assertJsonPath('meta.total', 1);
        $this->as($this->office)->getJson('/api/fee-payments?status=cancelled')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $bkash);
        $this->as($this->office)->getJson('/api/fee-payments?status=active')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $cash);
        $this->as($this->office)->getJson("/api/fee-payments?student_id={$this->enrolment->student_id}&collected_by={$this->office->id}")->assertJsonPath('meta.total', 2);
        $this->as($this->office)->getJson('/api/fee-payments?collected_by='.$this->admin->id)->assertJsonPath('meta.total', 0);
        $this->as($this->office)->getJson('/api/fee-payments?search=2026-000002')->assertJsonPath('meta.total', 1);
        // 2026-10-15 06:00 UTC is 12:00 in Dhaka: inside 2026-10-15, outside the day before.
        $this->as($this->office)->getJson('/api/fee-payments?from=2026-10-15&to=2026-10-15')->assertJsonPath('meta.total', 2);
        $this->as($this->office)->getJson('/api/fee-payments?from=2026-10-16')->assertJsonPath('meta.total', 0);
        $this->as($this->office)->getJson('/api/fee-payments?to=2026-10-14')->assertJsonPath('meta.total', 0);
        $this->as($this->office)->getJson('/api/fee-payments?from=2026-10-16&to=2026-10-15')->assertUnprocessable();
        $this->as($this->office)->getJson('/api/fee-payments?method=cheque')->assertUnprocessable();

        $this->as($this->office)->getJson("/api/fee-payments/{$cash}")
            ->assertOk()
            ->assertJsonPath('data.receipt_no', '2026-000001')
            ->assertJsonPath('data.student.student_id', $this->enrolment->student->student_id)
            ->assertJsonPath('data.allocations.0.due.enrolment.class.number', 10)
            ->assertJsonPath('data.allocations.0.due.head.code', 'TUITION')
            ->assertJsonPath('data.collected_by_name', $this->office->name);
        $this->as($this->office)->getJson('/api/fee-payments/999999')->assertNotFound();
        $this->as($this->office)->getJson('/api/fee-payments/1abc')->assertNotFound();
    }

    public function test_money_in_responses_is_decimal_2_strings(): void
    {
        $this->twoDues();
        $this->tuition->rates()->update(['amount' => '799.99']);
        $this->generateDues(['month' => '2026-11'])->assertOk();

        $data = $this->pay($this->enrolment, '1600.5')->assertCreated()->json('data');

        $this->assertSame('1600.50', $data['amount']);
        foreach ($data['allocations'] as $allocation) {
            $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $allocation['amount']);
            foreach (['amount', 'waiver_amount', 'net_amount', 'paid_amount', 'outstanding_amount'] as $key) {
                $this->assertIsString($allocation['due'][$key]);
                $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $allocation['due'][$key]);
            }
        }
    }
}
