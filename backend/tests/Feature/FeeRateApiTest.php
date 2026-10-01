<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\FeeHead;
use App\Models\FeeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFees;
use Tests\TestCase;

class FeeRateApiTest extends TestCase
{
    use BuildsFees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFees();
    }

    private function payload(array $extra = []): array
    {
        return [
            'fee_head_id' => $this->tuition->id,
            'class_id' => $this->class9->id,
            'academic_year_id' => $this->year->id,
            'amount' => '900',
            ...$extra,
        ];
    }

    public function test_an_admin_creates_a_rate_for_the_whole_class(): void
    {
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['due_day' => 5]))
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'fee_head_id', 'class_id', 'academic_year_id', 'group', 'amount', 'due_day', 'head', 'class'], 'message'])
            ->assertJsonPath('data.amount', '900.00')
            ->assertJsonPath('data.group', null)
            ->assertJsonPath('data.due_day', 5)
            ->assertJsonPath('data.head.code', 'TUITION')
            ->assertJsonPath('data.class.number', 9);
    }

    public function test_a_group_is_only_allowed_from_class_9(): void
    {
        $class8 = Classes::factory()->create(['number' => 8]);

        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['class_id' => $class8->id, 'group' => 'science']))
            ->assertUnprocessable()->assertJsonValidationErrors(['group']);
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['class_id' => $class8->id]))->assertCreated();
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['group' => 'science']))->assertCreated()->assertJsonPath('data.group', 'science');
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['group' => 'arts']))->assertUnprocessable()->assertJsonValidationErrors(['group']);
    }

    public function test_there_is_one_rate_per_head_class_year_and_group(): void
    {
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload())->assertCreated();
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['amount' => '1000']))
            ->assertUnprocessable()->assertJsonValidationErrors(['fee_head_id']);

        // A group makes it a different rate, and so does another year, class or head.
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['group' => 'science']))->assertCreated();
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['group' => 'science']))->assertUnprocessable()->assertJsonValidationErrors(['fee_head_id']);
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['academic_year_id' => AcademicYear::factory()->create(['year' => 2027])->id]))->assertCreated();
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['fee_head_id' => FeeHead::factory()->create()->id]))->assertCreated();
    }

    public function test_the_payload_is_validated(): void
    {
        $this->as($this->admin)->postJson('/api/fee-rates', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['fee_head_id', 'class_id', 'academic_year_id', 'amount']);
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['amount' => '-1']))->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['amount' => '10.555']))->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['amount' => '100000000']))->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['amount' => 'abc']))->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['due_day' => 0]))->assertUnprocessable()->assertJsonValidationErrors(['due_day']);
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['due_day' => 29]))->assertUnprocessable()->assertJsonValidationErrors(['due_day']);
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['fee_head_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors(['fee_head_id']);
        $this->as($this->admin)->postJson('/api/fee-rates', $this->payload(['amount' => '0']))->assertCreated();
    }

    public function test_a_rate_can_be_edited_after_dues_use_it_and_the_dues_keep_their_amount(): void
    {
        $this->enrolStudents(1);
        $rate = FeeRate::where('fee_head_id', $this->tuition->id)->firstOrFail();
        $this->generateDues()->assertOk();

        $this->as($this->admin)->putJson("/api/fee-rates/{$rate->id}", ['amount' => '950.00', 'due_day' => 12])
            ->assertOk()->assertJsonPath('data.amount', '950.00')->assertJsonPath('data.due_day', 12);

        $this->assertDatabaseHas('fee_dues', ['fee_head_id' => $this->tuition->id, 'amount' => '800.00']);
    }

    public function test_a_rates_head_class_and_year_cannot_change(): void
    {
        $rate = FeeRate::where('fee_head_id', $this->tuition->id)->firstOrFail();

        foreach (['fee_head_id' => $this->tuition->id, 'class_id' => $this->class9->id, 'academic_year_id' => $this->year->id] as $field => $value) {
            $this->as($this->admin)->putJson("/api/fee-rates/{$rate->id}", [$field => $value])->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }
    }

    public function test_updating_the_group_is_checked_against_the_class_and_duplicates(): void
    {
        $rate = $this->rate($this->tuition, Classes::factory()->create(['number' => 8]), '700.00');
        $this->as($this->admin)->putJson("/api/fee-rates/{$rate->id}", ['group' => 'science'])->assertUnprocessable()->assertJsonValidationErrors(['group']);

        $whole = $this->rate($this->tuition, $this->class9, '900.00');
        $science = $this->rate($this->tuition, $this->class9, '1100.00', 'science');
        $this->as($this->admin)->putJson("/api/fee-rates/{$whole->id}", ['group' => 'science'])->assertUnprocessable()->assertJsonValidationErrors(['fee_head_id']);
        $this->as($this->admin)->putJson("/api/fee-rates/{$science->id}", ['group' => 'humanities'])->assertOk();
    }

    public function test_index_filters_and_shows(): void
    {
        $this->rate($this->tuition, $this->class9, '900.00');

        $this->as($this->office)->getJson('/api/fee-rates')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'amount', 'head', 'class']], 'links', 'meta' => ['total']])
            ->assertJsonPath('meta.total', 2);
        $this->as($this->office)->getJson("/api/fee-rates?class_id={$this->class9->id}")->assertJsonPath('meta.total', 1);
        $this->as($this->office)->getJson("/api/fee-rates?academic_year_id={$this->year->id}&fee_head_id={$this->tuition->id}")->assertJsonPath('meta.total', 2);
        $this->as($this->office)->getJson('/api/fee-rates?class_id=abc')->assertUnprocessable();

        $rate = FeeRate::firstOrFail();
        $this->as($this->office)->getJson("/api/fee-rates/{$rate->id}")->assertOk()->assertJsonPath('data.head.code', 'TUITION');
        $this->as($this->office)->getJson('/api/fee-rates/999999')->assertNotFound();
    }

    public function test_deleting_is_refused_while_dues_use_the_rate(): void
    {
        $this->enrolStudents(1);
        $used = FeeRate::where('fee_head_id', $this->tuition->id)->firstOrFail();
        $unused = $this->rate($this->tuition, $this->class9, '900.00');
        $this->generateDues()->assertOk();

        $this->as($this->admin)->deleteJson("/api/fee-rates/{$used->id}")->assertStatus(409);
        $this->assertDatabaseHas('fee_rates', ['id' => $used->id]);

        $this->as($this->admin)->deleteJson("/api/fee-rates/{$unused->id}")->assertNoContent();
        $this->assertDatabaseMissing('fee_rates', ['id' => $unused->id]);
    }
}
