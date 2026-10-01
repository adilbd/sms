<?php

namespace Tests\Feature;

use App\Models\FeeHead;
use App\Models\StudentFeeWaiver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFees;
use Tests\TestCase;

class FeeWaiverApiTest extends TestCase
{
    use BuildsFees, RefreshDatabase;

    private int $studentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFees();
        $this->studentId = $this->enrolStudents(1)[0]->student_id;
    }

    private function payload(array $extra = []): array
    {
        return [
            'student_id' => $this->studentId,
            'academic_year_id' => $this->year->id,
            'fee_head_id' => $this->tuition->id,
            'percent' => 50,
            'reason' => 'Orphan',
            ...$extra,
        ];
    }

    public function test_an_office_clerk_creates_a_percent_waiver_and_is_recorded_as_the_approver(): void
    {
        $this->as($this->office)->postJson('/api/fee-waivers', $this->payload())
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'student_id', 'academic_year_id', 'fee_head_id', 'percent', 'fixed_amount', 'reason', 'approved_by', 'approved_by_name', 'head'], 'message'])
            ->assertJsonPath('data.percent', '50.00')
            ->assertJsonPath('data.fixed_amount', null)
            ->assertJsonPath('data.approved_by', $this->office->id);
    }

    public function test_a_fixed_waiver_is_a_decimal_2_string(): void
    {
        $this->as($this->admin)->postJson('/api/fee-waivers', $this->payload(['percent' => null, 'fixed_amount' => 125.5]))
            ->assertCreated()
            ->assertJsonPath('data.fixed_amount', '125.50')
            ->assertJsonPath('data.percent', null);
    }

    public function test_exactly_one_of_percent_and_fixed_amount_is_required(): void
    {
        $this->as($this->admin)->postJson('/api/fee-waivers', $this->payload(['percent' => 10, 'fixed_amount' => 100]))
            ->assertUnprocessable()->assertJsonValidationErrors(['percent']);
        $this->as($this->admin)->postJson('/api/fee-waivers', $this->payload(['percent' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['percent']);
        $this->as($this->admin)->postJson('/api/fee-waivers', ['student_id' => $this->studentId, 'academic_year_id' => $this->year->id, 'fee_head_id' => $this->tuition->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['percent']);
        $this->assertSame(0, StudentFeeWaiver::count());
    }

    public function test_the_percent_must_be_between_0_and_100(): void
    {
        $this->as($this->admin)->postJson('/api/fee-waivers', $this->payload(['percent' => 100.01]))->assertUnprocessable()->assertJsonValidationErrors(['percent']);
        $this->as($this->admin)->postJson('/api/fee-waivers', $this->payload(['percent' => 150]))->assertUnprocessable()->assertJsonValidationErrors(['percent']);
        $this->as($this->admin)->postJson('/api/fee-waivers', $this->payload(['percent' => -1]))->assertUnprocessable()->assertJsonValidationErrors(['percent']);
        $this->as($this->admin)->postJson('/api/fee-waivers', $this->payload(['percent' => 33.333]))->assertUnprocessable()->assertJsonValidationErrors(['percent']);
        $this->as($this->admin)->postJson('/api/fee-waivers', $this->payload(['percent' => 100]))->assertCreated();
    }

    public function test_the_other_fields_are_validated(): void
    {
        $this->as($this->admin)->postJson('/api/fee-waivers', [])->assertUnprocessable()->assertJsonValidationErrors(['student_id', 'academic_year_id', 'fee_head_id']);
        $this->as($this->admin)->postJson('/api/fee-waivers', $this->payload(['percent' => null, 'fixed_amount' => -5]))->assertUnprocessable()->assertJsonValidationErrors(['fixed_amount']);
        $this->as($this->admin)->postJson('/api/fee-waivers', $this->payload(['reason' => str_repeat('x', 256)]))->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->as($this->admin)->postJson('/api/fee-waivers', $this->payload(['student_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors(['student_id']);
    }

    public function test_one_waiver_per_student_year_and_head(): void
    {
        $this->as($this->admin)->postJson('/api/fee-waivers', $this->payload())->assertCreated();
        $this->as($this->admin)->postJson('/api/fee-waivers', $this->payload(['percent' => 10]))->assertUnprocessable()->assertJsonValidationErrors(['fee_head_id']);
        $this->as($this->admin)->postJson('/api/fee-waivers', $this->payload(['fee_head_id' => FeeHead::factory()->create()->id]))->assertCreated();
    }

    public function test_updating_checks_exactly_one_against_the_saved_waiver(): void
    {
        $waiver = $this->waive($this->enrolStudents(1)[0], $this->tuition, '50.00');

        $this->as($this->admin)->putJson("/api/fee-waivers/{$waiver->id}", ['fixed_amount' => 100])->assertUnprocessable()->assertJsonValidationErrors(['percent']);
        $this->as($this->admin)->putJson("/api/fee-waivers/{$waiver->id}", ['percent' => null, 'fixed_amount' => 100])
            ->assertOk()->assertJsonPath('data.fixed_amount', '100.00')->assertJsonPath('data.percent', null);
        $this->as($this->admin)->putJson("/api/fee-waivers/{$waiver->id}", ['reason' => 'Updated'])->assertOk()->assertJsonPath('data.reason', 'Updated');
        $this->as($this->admin)->putJson("/api/fee-waivers/{$waiver->id}", ['student_id' => $this->studentId])->assertUnprocessable()->assertJsonValidationErrors(['student_id']);
    }

    public function test_index_filters_shows_and_deletes(): void
    {
        $other = $this->enrolStudents(1)[0];
        $waiver = $this->waive($other, $this->tuition, '25.00');
        $this->waive($this->enrolStudents(1)[0], $this->tuition, '10.00');

        $this->as($this->office)->getJson('/api/fee-waivers')
            ->assertOk()->assertJsonStructure(['data' => [['id', 'percent', 'head']], 'links', 'meta' => ['total']])->assertJsonPath('meta.total', 2);
        $this->as($this->office)->getJson("/api/fee-waivers?student_id={$other->student_id}")->assertJsonPath('meta.total', 1);
        $this->as($this->office)->getJson("/api/fee-waivers/{$waiver->id}")->assertOk()->assertJsonPath('data.percent', '25.00');
        $this->as($this->office)->getJson('/api/fee-waivers/999999')->assertNotFound();

        $this->as($this->office)->deleteJson("/api/fee-waivers/{$waiver->id}")->assertNoContent();
        $this->assertDatabaseMissing('student_fee_waivers', ['id' => $waiver->id]);
    }
}
