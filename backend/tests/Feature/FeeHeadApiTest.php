<?php

namespace Tests\Feature;

use App\Models\FeeHead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsFees;
use Tests\TestCase;

class FeeHeadApiTest extends TestCase
{
    use BuildsFees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFees();
    }

    public function test_an_admin_creates_a_head_with_an_uppercased_code(): void
    {
        $this->as($this->admin)->postJson('/api/fee-heads', ['name_en' => 'Session Fee', 'name_bn' => 'সেশন ফি', 'code' => ' session ', 'kind' => 'one_time'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'name_en', 'name_bn', 'code', 'kind', 'is_active', 'created_at', 'updated_at'], 'message'])
            ->assertJsonPath('data.code', 'SESSION')
            ->assertJsonPath('data.is_active', true);
    }

    public function test_one_name_is_enough_and_at_least_one_is_required(): void
    {
        $this->as($this->admin)->postJson('/api/fee-heads', ['name_bn' => 'বেতন', 'code' => 'BETON', 'kind' => 'monthly'])->assertCreated();
        $this->as($this->admin)->postJson('/api/fee-heads', ['code' => 'NONAME', 'kind' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name_en']);
    }

    public function test_the_payload_is_validated(): void
    {
        $this->as($this->admin)->postJson('/api/fee-heads', [])->assertUnprocessable()->assertJsonValidationErrors(['code', 'kind']);
        $this->as($this->admin)->postJson('/api/fee-heads', ['name_en' => 'X', 'code' => 'tuition', 'kind' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->as($this->admin)->postJson('/api/fee-heads', ['name_en' => 'X', 'code' => 'BAD CODE', 'kind' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->as($this->admin)->postJson('/api/fee-heads', ['name_en' => 'X', 'code' => 'OK', 'kind' => 'yearly'])
            ->assertUnprocessable()->assertJsonValidationErrors(['kind']);
        $this->as($this->admin)->postJson('/api/fee-heads', ['name_en' => str_repeat('x', 256), 'code' => 'OK', 'kind' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name_en']);
    }

    public function test_update_keeps_its_own_code_but_not_another_heads(): void
    {
        $other = FeeHead::factory()->create(['code' => 'OTHER']);

        $this->as($this->admin)->putJson("/api/fee-heads/{$this->tuition->id}", ['code' => 'TUITION', 'name_en' => 'Tuition'])->assertOk()->assertJsonPath('data.name_en', 'Tuition');
        $this->as($this->admin)->putJson("/api/fee-heads/{$this->tuition->id}", ['code' => 'other'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->as($this->admin)->putJson("/api/fee-heads/{$other->id}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    }

    public function test_a_partial_update_cannot_clear_both_names(): void
    {
        $head = FeeHead::factory()->create(['name_en' => 'Only English', 'name_bn' => null]);

        $this->as($this->admin)->putJson("/api/fee-heads/{$head->id}", ['name_en' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['name_en']);
    }

    public function test_the_kind_cannot_change_once_the_head_has_dues(): void
    {
        $this->enrolStudents(1);
        $free = FeeHead::factory()->monthly()->create();

        $this->as($this->admin)->putJson("/api/fee-heads/{$free->id}", ['kind' => 'one_time'])->assertOk();

        $this->generateDues()->assertOk();
        $this->as($this->admin)->putJson("/api/fee-heads/{$this->tuition->id}", ['kind' => 'one_time'])
            ->assertUnprocessable()->assertJsonValidationErrors(['kind']);
        $this->as($this->admin)->putJson("/api/fee-heads/{$this->tuition->id}", ['kind' => 'monthly', 'name_en' => 'Tuition'])->assertOk();
    }

    public function test_index_filters_and_shows(): void
    {
        FeeHead::factory()->oneTime()->create(['code' => 'SESSION', 'name_en' => 'Session Fee']);
        FeeHead::factory()->create(['code' => 'OLD', 'is_active' => false]);

        $this->as($this->office)->getJson('/api/fee-heads')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'code', 'kind']], 'links', 'meta' => ['total']])
            ->assertJsonPath('meta.total', 3);
        $this->as($this->office)->getJson('/api/fee-heads?kind=one_time')->assertJsonPath('meta.total', 1);
        $this->as($this->office)->getJson('/api/fee-heads?is_active=false')->assertJsonPath('meta.total', 1);
        $this->as($this->office)->getJson('/api/fee-heads?search=session')->assertJsonPath('meta.total', 1);
        $this->as($this->office)->getJson('/api/fee-heads?search[]=x')->assertUnprocessable();
        $this->as($this->office)->getJson('/api/fee-heads?kind=nope')->assertUnprocessable();
        $this->as($this->office)->getJson('/api/fee-heads?per_page=101')->assertUnprocessable();
        $this->as($this->office)->getJson("/api/fee-heads/{$this->tuition->id}")->assertOk()->assertJsonPath('data.code', 'TUITION');
        $this->as($this->office)->getJson('/api/fee-heads/999999')->assertNotFound();
        $this->as($this->office)->getJson('/api/fee-heads/1abc')->assertNotFound();
    }

    public function test_deleting_is_refused_while_rates_or_dues_use_the_head(): void
    {
        // The tuition head has a rate.
        $this->as($this->admin)->deleteJson("/api/fee-heads/{$this->tuition->id}")->assertStatus(409);
        $this->assertDatabaseHas('fee_heads', ['id' => $this->tuition->id, 'deleted_at' => null]);

        $this->tuition->rates()->delete();
        $this->enrolStudents(1);
        $this->rate($this->tuition, $this->class10, '800.00');
        $this->generateDues()->assertOk();
        $this->tuition->rates()->delete();
        $this->as($this->admin)->deleteJson("/api/fee-heads/{$this->tuition->id}")->assertStatus(409);

        $unused = FeeHead::factory()->create();
        $this->as($this->admin)->deleteJson("/api/fee-heads/{$unused->id}")->assertNoContent();
        $this->assertSoftDeleted('fee_heads', ['id' => $unused->id]);
    }

    public function test_a_head_with_only_waivers_cannot_be_deleted(): void
    {
        $enrolment = $this->enrolStudents(1)[0];
        $head = FeeHead::factory()->create();
        $this->waive($enrolment, $head, '50');

        $this->as($this->admin)->deleteJson("/api/fee-heads/{$head->id}")->assertStatus(409);
        $this->assertDatabaseHas('fee_heads', ['id' => $head->id, 'deleted_at' => null]);
    }

    public function test_a_deleted_heads_code_stays_taken(): void
    {
        $unused = FeeHead::factory()->create(['code' => 'GONE']);
        $this->as($this->admin)->deleteJson("/api/fee-heads/{$unused->id}")->assertNoContent();

        $this->as($this->admin)->postJson('/api/fee-heads', ['name_en' => 'X', 'code' => 'GONE', 'kind' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
    }
}
