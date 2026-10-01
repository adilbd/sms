<?php

namespace Tests\Feature;

use App\Models\FeeDue;
use App\Models\FeeHead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFees;
use Tests\TestCase;

class FeeDueApiTest extends TestCase
{
    use BuildsFees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFees();
    }

    public function test_generating_a_month_creates_one_due_per_student_and_is_idempotent(): void
    {
        $this->enrolStudents(5);

        $this->generateDues()
            ->assertOk()
            ->assertJsonPath('data', ['created' => 5, 'skipped' => 0, 'no_rate' => 0])
            ->assertJsonPath('message', 'Fee dues generated successfully');

        $this->assertSame(5, FeeDue::count());
        $this->assertDatabaseHas('fee_dues', [
            'fee_head_id' => $this->tuition->id, 'period' => '2026-10', 'amount' => '800.00',
            'waiver_amount' => '0.00', 'net_amount' => '800.00', 'paid_amount' => '0.00',
            'status' => 'unpaid', 'due_date' => '2026-10-10',
        ]);

        $this->generateDues()->assertOk()->assertJsonPath('data', ['created' => 0, 'skipped' => 5, 'no_rate' => 0]);
        $this->assertSame(5, FeeDue::count());
    }

    public function test_an_existing_due_is_never_overwritten(): void
    {
        [$enrolment] = $this->enrolStudents(1);
        $this->generateDues()->assertOk();
        $due = FeeDue::firstOrFail();
        $due->update(['paid_amount' => '300.00', 'status' => 'partial']);

        // The rate changes and the dues are generated again: the saved due is untouched.
        $this->tuition->rates()->update(['amount' => '1000.00']);
        $this->generateDues()->assertJsonPath('data.created', 0);

        $this->assertSame('800.00', $due->fresh()->amount);
        $this->assertSame('partial', $due->fresh()->status);
        $this->assertSame($enrolment->student_id, $due->student_id);
    }

    public function test_the_rates_due_day_sets_the_due_date(): void
    {
        $this->enrolStudents(1);
        $this->tuition->rates()->update(['due_day' => 5]);

        $this->generateDues()->assertOk();

        $this->assertSame('2026-10-05', FeeDue::firstOrFail()->due_date->toDateString());
    }

    public function test_waivers_apply_when_the_due_is_generated(): void
    {
        [$half, $full, $fixed, $over, $none] = $this->enrolStudents(5);
        $this->waive($half, $this->tuition, '50.00');
        $this->waive($full, $this->tuition, '100.00');
        $this->waive($fixed, $this->tuition, null, '125.50');
        $this->waive($over, $this->tuition, null, '5000.00');

        $this->generateDues()->assertOk()->assertJsonPath('data.created', 5);

        $due = fn ($enrolment) => FeeDue::where('enrolment_id', $enrolment->id)->firstOrFail();

        $this->assertSame(['400.00', '400.00', 'unpaid'], [$due($half)->waiver_amount, $due($half)->net_amount, $due($half)->status]);
        $this->assertSame(['800.00', '0.00', 'waived'], [$due($full)->waiver_amount, $due($full)->net_amount, $due($full)->status]);
        $this->assertSame(['125.50', '674.50'], [$due($fixed)->waiver_amount, $due($fixed)->net_amount]);
        // A fixed waiver above the amount is capped at the amount.
        $this->assertSame(['800.00', '0.00', 'waived'], [$due($over)->waiver_amount, $due($over)->net_amount, $due($over)->status]);
        $this->assertSame(['0.00', '800.00'], [$due($none)->waiver_amount, $due($none)->net_amount]);
    }

    public function test_a_waiver_added_later_does_not_change_existing_dues(): void
    {
        [$enrolment] = $this->enrolStudents(1);
        $this->generateDues()->assertOk();

        $this->waive($enrolment, $this->tuition, '50.00');
        $this->generateDues()->assertJsonPath('data.created', 0);

        $this->assertSame('800.00', FeeDue::firstOrFail()->net_amount);
    }

    public function test_a_group_rate_wins_over_the_class_wide_rate(): void
    {
        $science = $this->enrol($this->section9, 'science', null, 1);
        $humanities = $this->enrol($this->section9, 'humanities', null, 2);
        $this->rate($this->tuition, $this->class9, '900.00');
        $this->rate($this->tuition, $this->class9, '1100.00', 'science');

        $this->generateDues()->assertOk()->assertJsonPath('data.created', 2);

        $this->assertSame('1100.00', FeeDue::where('enrolment_id', $science->id)->firstOrFail()->amount);
        $this->assertSame('900.00', FeeDue::where('enrolment_id', $humanities->id)->firstOrFail()->amount);
    }

    public function test_a_class_without_a_rate_gets_no_due(): void
    {
        $this->enrolStudents(2);
        $this->enrol($this->section9, 'science', null, 1);

        $this->generateDues()->assertOk()->assertJsonPath('data', ['created' => 2, 'skipped' => 0, 'no_rate' => 1]);
    }

    public function test_a_dry_run_counts_without_creating(): void
    {
        $this->enrolStudents(3);

        $this->generateDues(['dry_run' => true])
            ->assertOk()
            ->assertJsonPath('data', ['created' => 3, 'skipped' => 0, 'no_rate' => 0])
            ->assertJsonPath('message', 'Preview of the dues to generate');
        $this->assertSame(0, FeeDue::count());

        $this->generateDues()->assertOk();
        $this->generateDues(['dry_run' => true])->assertJsonPath('data', ['created' => 0, 'skipped' => 3, 'no_rate' => 0]);
    }

    public function test_without_a_month_it_generates_january_up_to_the_current_month(): void
    {
        $this->travelTo('2026-03-20 06:00:00');
        $this->enrolStudents(2);

        $this->generateDues(['month' => null])->assertOk()->assertJsonPath('data.created', 6);

        $this->assertEqualsCanonicalizing(['2026-01', '2026-02', '2026-03'], FeeDue::pluck('period')->unique()->all());
    }

    public function test_the_current_month_is_the_asia_dhaka_month(): void
    {
        // 2026-03-31 20:00 UTC is already April 1st in Dhaka.
        $this->travelTo('2026-03-31 20:00:00');
        $this->enrolStudents(1);

        $this->generateDues(['month' => null])->assertOk()->assertJsonPath('data.created', 4);
    }

    public function test_a_student_enrolled_part_way_through_the_year_owes_no_earlier_month(): void
    {
        [$late, $early] = $this->enrolStudents(2);
        $late->update(['enrolled_on' => '2026-08-12']);

        $this->generateDues(['month' => null])->assertOk();

        $this->assertEqualsCanonicalizing(['2026-08', '2026-09', '2026-10'], FeeDue::where('enrolment_id', $late->id)->pluck('period')->all());
        $this->assertSame(10, FeeDue::where('enrolment_id', $early->id)->count());
    }

    public function test_only_active_enrolments_get_dues(): void
    {
        [$active, $left] = $this->enrolStudents(2);
        $left->update(['status' => 'left']);

        $this->generateDues()->assertOk()->assertJsonPath('data.created', 1);

        $this->assertDatabaseMissing('fee_dues', ['enrolment_id' => $left->id]);
        $this->assertDatabaseHas('fee_dues', ['enrolment_id' => $active->id]);
    }

    public function test_it_can_be_limited_to_a_class_section_or_head(): void
    {
        $this->enrolStudents(2);
        $other = \App\Models\Section::factory()->create(['class_id' => $this->class10->id, 'shift_id' => $this->shift->id]);
        $this->enrolStudents(3, $other);
        $session = FeeHead::factory()->oneTime()->create();
        $this->rate($session, $this->class10, '1500.00');

        $this->generateDues(['section_id' => $other->id, 'fee_head_id' => $this->tuition->id])->assertJsonPath('data.created', 3);
        $this->generateDues(['class_id' => $this->class9->id])->assertJsonPath('data.created', 0);
    }

    public function test_a_one_time_head_is_charged_once_per_enrolment(): void
    {
        $this->enrolStudents(2);
        $session = FeeHead::factory()->oneTime()->create(['code' => 'SESSION']);
        $this->rate($session, $this->class10, '1500.00', null, 20);

        $this->generateDues(['fee_head_id' => $session->id])->assertOk()->assertJsonPath('data.created', 2);
        // A different month does not charge it again.
        $this->generateDues(['fee_head_id' => $session->id, 'month' => '2026-11'])->assertJsonPath('data', ['created' => 0, 'skipped' => 2, 'no_rate' => 0]);

        $this->assertDatabaseHas('fee_dues', ['fee_head_id' => $session->id, 'period' => 'one_time', 'amount' => '1500.00', 'due_date' => '2026-10-20']);
    }

    public function test_a_per_exam_head_is_charged_once_per_exam_for_the_exams_classes(): void
    {
        $this->enrolStudents(2);
        $this->enrol($this->section9, 'science', null, 1);
        $exam = $this->createExam([$this->class10]);
        $examFee = FeeHead::factory()->perExam()->create(['code' => 'EXAMFEE']);
        $this->rate($examFee, $this->class10, '300.00');
        $this->rate($examFee, $this->class9, '300.00');

        // Without an exam the per-exam head is skipped; with one, only that head is charged.
        $this->generateDues()->assertJsonPath('data.created', 2);
        $this->generateDues(['exam_id' => $exam->id, 'month' => null])
            ->assertOk()
            ->assertJsonPath('data.created', 2);
        $this->generateDues(['exam_id' => $exam->id, 'month' => null])->assertJsonPath('data', ['created' => 0, 'skipped' => 2, 'no_rate' => 0]);

        $this->assertDatabaseHas('fee_dues', ['fee_head_id' => $examFee->id, 'period' => "exam:{$exam->id}", 'due_date' => '2026-06-01']);
        $this->assertSame(0, FeeDue::where('fee_head_id', $examFee->id)->whereHas('enrolment', fn ($q) => $q->where('class_id', $this->class9->id))->count());
    }

    public function test_exam_and_head_combinations_are_validated(): void
    {
        $exam = $this->createExam([$this->class10]);
        $examFee = FeeHead::factory()->perExam()->create();
        $inactive = FeeHead::factory()->create(['is_active' => false]);

        $this->generateDues(['fee_head_id' => $examFee->id])->assertUnprocessable()->assertJsonValidationErrors(['exam_id']);
        $this->generateDues(['fee_head_id' => $this->tuition->id, 'exam_id' => $exam->id])->assertUnprocessable()->assertJsonValidationErrors(['fee_head_id']);
        $this->generateDues(['fee_head_id' => $inactive->id])->assertUnprocessable()->assertJsonValidationErrors(['fee_head_id']);

        $otherYear = \App\Models\Exam::factory()->create();
        $this->generateDues(['exam_id' => $otherYear->id])->assertUnprocessable()->assertJsonValidationErrors(['exam_id']);
    }

    public function test_the_payload_is_validated(): void
    {
        $this->as($this->admin)->postJson('/api/fee-dues/generate', [])->assertUnprocessable()->assertJsonValidationErrors(['academic_year_id']);

        foreach (['2026-13', '2026-1', 'October', '26-10', '2026-10-01'] as $month) {
            $this->generateDues(['month' => $month])->assertUnprocessable()->assertJsonValidationErrors(['month']);
        }

        // A well-formed month outside the academic year.
        $this->generateDues(['month' => '2027-01'])->assertUnprocessable()->assertJsonValidationErrors(['month']);
        $this->generateDues(['class_id' => 9999])->assertUnprocessable()->assertJsonValidationErrors(['class_id']);
        $this->generateDues(['section_id' => 9999])->assertUnprocessable()->assertJsonValidationErrors(['section_id']);
        $this->generateDues(['fee_head_id' => 9999])->assertUnprocessable()->assertJsonValidationErrors(['fee_head_id']);
    }

    public function test_the_list_is_paginated_and_filtered(): void
    {
        [$a, $b] = $this->enrolStudents(2);
        $this->generateDues()->assertOk();
        FeeDue::where('enrolment_id', $b->id)->update(['status' => 'paid', 'paid_amount' => '800.00']);

        $this->as($this->office)->getJson('/api/fee-dues')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'student_id', 'enrolment_id', 'fee_head_id', 'period', 'amount', 'waiver_amount', 'net_amount', 'paid_amount', 'outstanding_amount', 'status', 'due_date', 'head', 'student', 'enrolment']], 'links', 'meta' => ['total']])
            ->assertJsonPath('meta.total', 2);

        $this->as($this->office)->getJson("/api/fee-dues?student_id={$a->student_id}")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.outstanding_amount', '800.00');
        $this->as($this->office)->getJson('/api/fee-dues?status=paid')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.outstanding_amount', '0.00');
        $this->as($this->office)->getJson("/api/fee-dues?section_id={$this->section10->id}&month=2026-10")->assertJsonPath('meta.total', 2);
        $this->as($this->office)->getJson('/api/fee-dues?month=2026-09')->assertJsonPath('meta.total', 0);
        $this->as($this->office)->getJson('/api/fee-dues?month=2026-02')->assertOk();
        $this->as($this->office)->getJson('/api/fee-dues?month=2026-13')->assertUnprocessable();
        $this->as($this->office)->getJson('/api/fee-dues?status=bogus')->assertUnprocessable();
        $this->as($this->office)->getJson('/api/fee-dues?per_page=101')->assertUnprocessable();
    }
}
