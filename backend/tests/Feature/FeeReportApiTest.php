<?php

namespace Tests\Feature;

use App\Models\FeeDue;
use App\Models\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFees;
use Tests\TestCase;

class FeeReportApiTest extends TestCase
{
    use BuildsFees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFees();
    }

    public function test_the_dues_report_totals_each_student_of_a_section_for_a_month(): void
    {
        [$a, $b, $c] = $this->enrolStudents(3);
        $this->waive($c, $this->tuition, '100.00');
        $this->generateDues(['month' => '2026-09'])->assertOk();
        $this->generateDues(['month' => '2026-10'])->assertOk();
        // Another section's dues never appear.
        $this->enrolStudents(1, Section::factory()->create(['class_id' => $this->class10->id, 'shift_id' => $this->shift->id]));
        $this->generateDues()->assertOk();
        $this->pay($b, '1000.00')->assertCreated();

        $this->as($this->office)->getJson("/api/fee-reports/dues?section_id={$this->section10->id}&month=2026-10")
            ->assertOk()
            ->assertJsonStructure(['data' => ['academic_year_id', 'section_id', 'month', 'rows' => [['student' => ['id', 'student_id', 'name_en', 'name_bn'], 'roll_number', 'due_count', 'net_amount', 'paid_amount', 'outstanding_amount']], 'totals' => ['net_amount', 'paid_amount', 'outstanding_amount']]])
            ->assertJsonCount(3, 'data.rows')
            ->assertJsonPath('data.rows.0.student.id', $a->student_id)
            ->assertJsonPath('data.rows.0.roll_number', 1)
            ->assertJsonPath('data.rows.0.net_amount', '800.00')
            ->assertJsonPath('data.rows.0.outstanding_amount', '800.00')
            // Student b's 1000 paid September (800) and 200 of October.
            ->assertJsonPath('data.rows.1.paid_amount', '200.00')
            ->assertJsonPath('data.rows.1.outstanding_amount', '600.00')
            ->assertJsonPath('data.rows.2.net_amount', '0.00')
            ->assertJsonPath('data.totals', ['net_amount' => '1600.00', 'paid_amount' => '200.00', 'outstanding_amount' => '1400.00']);

        // The whole year includes September too.
        $this->as($this->office)->getJson("/api/fee-reports/dues?section_id={$this->section10->id}")
            ->assertJsonPath('data.totals', ['net_amount' => '3200.00', 'paid_amount' => '1000.00', 'outstanding_amount' => '2200.00'])
            ->assertJsonPath('data.rows.0.due_count', 2);
    }

    public function test_the_dues_report_is_validated(): void
    {
        $this->as($this->office)->getJson('/api/fee-reports/dues')->assertUnprocessable()->assertJsonValidationErrors(['section_id']);
        $this->as($this->office)->getJson('/api/fee-reports/dues?section_id=999999')->assertUnprocessable()->assertJsonValidationErrors(['section_id']);
        $this->as($this->office)->getJson("/api/fee-reports/dues?section_id={$this->section10->id}&month=2026-13")->assertUnprocessable()->assertJsonValidationErrors(['month']);
        $this->as($this->office)->getJson("/api/fee-reports/dues?section_id={$this->section10->id}&academic_year_id=999999")->assertUnprocessable();
    }

    public function test_the_collection_report_totals_by_method_and_collector(): void
    {
        [$a, $b] = $this->enrolStudents(2);
        $this->generateDues()->assertOk();
        $this->pay($a, '500.00')->assertCreated();
        $this->pay($b, '300.25', 'bkash', ['transaction_id' => 'T1'])->assertCreated();
        $this->as($this->admin)->postJson('/api/fee-payments', ['student_id' => $a->student_id, 'amount' => '100.00', 'method' => 'cash'])->assertCreated();
        $cancelled = $this->pay($b, '50.00')->assertCreated()->json('data.id');
        $this->as($this->admin)->postJson("/api/fee-payments/{$cancelled}/cancel", ['reason' => 'x'])->assertOk();
        // Yesterday's payment is outside the range.
        $this->travelTo('2026-10-14 06:00:00');
        $this->pay($a, '25.00')->assertCreated();

        $report = $this->as($this->office)->getJson('/api/fee-reports/collection?from=2026-10-15&to=2026-10-15')
            ->assertOk()
            ->assertJsonStructure(['data' => ['from', 'to', 'totals' => ['count', 'amount'], 'by_method' => [['method', 'count', 'amount']], 'by_collector' => [['user_id', 'name', 'count', 'amount']], 'receipts' => [['id', 'receipt_no', 'paid_at', 'student', 'method', 'transaction_id', 'amount', 'collected_by', 'collector_name', 'is_cancelled']]]])
            ->assertJsonPath('data.totals', ['count' => 3, 'amount' => '900.25'])
            ->assertJsonCount(4, 'data.receipts')
            ->json('data');

        $byMethod = collect($report['by_method'])->keyBy('method');
        $this->assertSame(['count' => 2, 'amount' => '600.00'], ['count' => $byMethod['cash']['count'], 'amount' => $byMethod['cash']['amount']]);
        $this->assertSame('300.25', $byMethod['bkash']['amount']);
        $this->assertSame(['0.00', '0.00'], [$byMethod['nagad']['amount'], $byMethod['rocket']['amount']]);

        $byCollector = collect($report['by_collector'])->keyBy('user_id');
        $this->assertSame('800.25', $byCollector[$this->office->id]['amount']);
        $this->assertSame('100.00', $byCollector[$this->admin->id]['amount']);
        $this->assertSame(1, collect($report['receipts'])->where('is_cancelled', true)->count());

        // Filters and a wider range.
        $this->as($this->office)->getJson('/api/fee-reports/collection?from=2026-10-14&to=2026-10-15')->assertJsonPath('data.totals.amount', '925.25');
        $this->as($this->office)->getJson('/api/fee-reports/collection?from=2026-10-14&to=2026-10-15&method=bkash')->assertJsonPath('data.totals.amount', '300.25');
        $this->as($this->office)->getJson("/api/fee-reports/collection?from=2026-10-14&to=2026-10-15&collected_by={$this->admin->id}")->assertJsonPath('data.totals.amount', '100.00');
        $this->as($this->office)->getJson('/api/fee-reports/collection?from=2026-11-01&to=2026-11-30')->assertJsonPath('data.totals', ['count' => 0, 'amount' => '0.00']);
    }

    public function test_the_collection_range_uses_asia_dhaka_dates(): void
    {
        [$a] = $this->enrolStudents(1);
        $this->generateDues()->assertOk();
        // 18:30 UTC on the 14th is 00:30 on the 15th in Dhaka.
        $this->travelTo('2026-10-14 18:30:00');
        $this->pay($a, '100.00')->assertCreated();

        $this->as($this->office)->getJson('/api/fee-reports/collection?from=2026-10-15&to=2026-10-15')->assertJsonPath('data.totals.count', 1);
        $this->as($this->office)->getJson('/api/fee-reports/collection?from=2026-10-14&to=2026-10-14')->assertJsonPath('data.totals.count', 0);
    }

    public function test_the_collection_report_is_validated(): void
    {
        $this->as($this->office)->getJson('/api/fee-reports/collection')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->as($this->office)->getJson('/api/fee-reports/collection?from=2026-10-15&to=2026-10-14')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->as($this->office)->getJson('/api/fee-reports/collection?from=15/10/2026&to=2026-10-14')->assertUnprocessable()->assertJsonValidationErrors(['from']);
        $this->as($this->office)->getJson('/api/fee-reports/collection?from=2025-01-01&to=2026-10-14')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->as($this->office)->getJson('/api/fee-reports/collection?from=2026-01-01&to=2026-10-14&method=cheque')->assertUnprocessable()->assertJsonValidationErrors(['method']);
        $this->as($this->office)->getJson('/api/fee-reports/collection?from=2026-01-01&to=2026-10-14&collected_by=999999')->assertUnprocessable()->assertJsonValidationErrors(['collected_by']);
    }

    public function test_the_ledger_running_balance_and_cancelled_payments(): void
    {
        [$a, $other] = $this->enrolStudents(2);
        $this->generateDues(['month' => '2026-09'])->assertOk();
        $this->generateDues(['month' => '2026-10'])->assertOk();
        $first = $this->pay($a, '1000.00')->assertCreated()->json('data.id');
        // 2026-10-20 06:00 UTC is the same Dhaka day.
        $this->travelTo('2026-10-20 06:00:00');
        $this->pay($a, '100.00', 'bkash', ['transaction_id' => 'T9'])->assertCreated();
        $this->pay($other, '800.00')->assertCreated();

        $ledger = $this->as($this->office)->getJson("/api/fee-reports/students/{$a->student_id}/ledger")
            ->assertOk()
            ->assertJsonStructure(['data' => ['student' => ['id', 'student_id'], 'academic_year_id', 'entries' => [['type', 'id', 'date', 'reference', 'debit', 'credit', 'balance', 'is_cancelled']], 'totals' => ['billed', 'paid', 'balance']]])
            ->json('data');

        $this->assertSame(
            [
                ['due', '2026-09-10', '800.00', '0.00', '800.00'],
                ['due', '2026-10-10', '800.00', '0.00', '1600.00'],
                ['payment', '2026-10-15', '0.00', '1000.00', '600.00'],
                ['payment', '2026-10-20', '0.00', '100.00', '500.00'],
            ],
            array_map(fn ($e) => [$e['type'], $e['date'], $e['debit'], $e['credit'], $e['balance']], $ledger['entries']),
        );
        $this->assertSame(['billed' => '1600.00', 'paid' => '1100.00', 'balance' => '500.00'], $ledger['totals']);
        $this->assertSame('2026-000001', $ledger['entries'][2]['reference']);

        // A cancelled payment is listed but does not move the balance.
        $this->as($this->admin)->postJson("/api/fee-payments/{$first}/cancel", ['reason' => 'x'])->assertOk();
        $ledger = $this->as($this->office)->getJson("/api/fee-reports/students/{$a->student_id}/ledger")->json('data');
        $cancelled = collect($ledger['entries'])->firstWhere('is_cancelled', true);
        $this->assertSame(['0.00', '1500.00'], [$cancelled['credit'], $ledger['totals']['balance']]);
        $this->assertCount(4, $ledger['entries']);
    }

    public function test_the_ledger_is_per_year_and_validated(): void
    {
        [$a] = $this->enrolStudents(1);
        $this->generateDues()->assertOk();
        $other = \App\Models\AcademicYear::factory()->create(['year' => 2027]);

        $this->as($this->office)->getJson("/api/fee-reports/students/{$a->student_id}/ledger?academic_year_id={$other->id}")
            ->assertOk()->assertJsonCount(0, 'data.entries')->assertJsonPath('data.totals.balance', '0.00');
        $this->as($this->office)->getJson("/api/fee-reports/students/{$a->student_id}/ledger?academic_year_id=999999")->assertUnprocessable();
        $this->as($this->office)->getJson('/api/fee-reports/students/999999/ledger')->assertNotFound();
        $this->as($this->office)->getJson('/api/fee-reports/students/1abc/ledger')->assertNotFound();
    }

    public function test_reports_need_an_academic_year(): void
    {
        \App\Models\AcademicYear::query()->update(['is_active' => false]);

        $this->as($this->office)->getJson("/api/fee-reports/dues?section_id={$this->section10->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['academic_year_id']);
        $this->assertSame(0, FeeDue::count());
    }
}
