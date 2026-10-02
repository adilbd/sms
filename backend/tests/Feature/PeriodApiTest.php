<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\RoutineSlot;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExams;
use Tests\TestCase;

class PeriodApiTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpExams();
    }

    private function payload(array $extra = []): array
    {
        return [
            'shift_id' => $this->shift->id, 'number' => 1, 'name_en' => '1st period', 'name_bn' => 'প্রথম পিরিয়ড',
            'start_time' => '08:00', 'end_time' => '08:45', ...$extra,
        ];
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/periods')->assertUnauthorized();
        $this->postJson('/api/periods', $this->payload())->assertUnauthorized();
    }

    public function test_store_creates_a_period_with_the_resource_shape(): void
    {
        $this->as($this->admin)->postJson('/api/periods', $this->payload(['is_break' => false]))
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'shift_id', 'number', 'name_en', 'name_bn', 'start_time', 'end_time', 'is_break', 'created_at', 'updated_at'], 'message'])
            ->assertJsonPath('data.start_time', '08:00')
            ->assertJsonPath('data.end_time', '08:45')
            ->assertJsonPath('data.is_break', false);

        $this->assertDatabaseHas('periods', ['shift_id' => $this->shift->id, 'number' => 1, 'start_time' => '08:00:00']);
    }

    public function test_index_lists_a_shifts_periods_in_number_order_and_any_signed_in_user_can_read(): void
    {
        $other = Shift::factory()->create();
        Period::factory()->create(['shift_id' => $this->shift->id, 'number' => 2, 'start_time' => '09:00:00', 'end_time' => '09:45:00']);
        Period::factory()->create(['shift_id' => $this->shift->id, 'number' => 1]);
        Period::factory()->create(['shift_id' => $other->id, 'number' => 1]);

        $this->as($this->userWithRole('teacher'))->getJson("/api/periods?shift_id={$this->shift->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'number']], 'links', 'meta' => ['total']])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.number', 1)
            ->assertJsonPath('data.1.number', 2);

        $this->as($this->userWithRole('student'))->getJson('/api/periods')->assertOk();
    }

    public function test_show_update_and_destroy(): void
    {
        $period = Period::factory()->create(['shift_id' => $this->shift->id, 'number' => 1]);

        $this->as($this->admin)->getJson("/api/periods/{$period->id}")->assertOk()->assertJsonPath('data.id', $period->id);
        $this->as($this->admin)->putJson("/api/periods/{$period->id}", ['name_en' => 'First', 'end_time' => '08:50'])
            ->assertOk()->assertJsonPath('data.name_en', 'First')->assertJsonPath('data.end_time', '08:50');
        $this->as($this->admin)->deleteJson("/api/periods/{$period->id}")->assertNoContent();
        $this->assertDatabaseMissing('periods', ['id' => $period->id]);
        $this->as($this->admin)->getJson("/api/periods/{$period->id}")->assertNotFound();
    }

    public function test_writes_need_edit_settings(): void
    {
        $period = Period::factory()->create(['shift_id' => $this->shift->id, 'number' => 1]);

        foreach (['teacher', 'office', 'student', 'parent'] as $role) {
            $user = $this->userWithRole($role);
            $this->as($user)->postJson('/api/periods', $this->payload(['number' => 5]))->assertForbidden();
            $this->as($user)->putJson("/api/periods/{$period->id}", ['name_en' => 'x'])->assertForbidden();
            $this->as($user)->deleteJson("/api/periods/{$period->id}")->assertForbidden();
        }
    }

    public function test_store_validates_the_fields(): void
    {
        $this->as($this->admin)->postJson('/api/periods', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['shift_id', 'number', 'name_en', 'name_bn', 'start_time', 'end_time']);

        $this->as($this->admin)->postJson('/api/periods', $this->payload(['start_time' => '8am', 'shift_id' => 9999]))
            ->assertUnprocessable()->assertJsonValidationErrors(['start_time', 'shift_id']);
    }

    public function test_a_duplicate_number_in_the_same_shift_is_422_but_another_shift_may_reuse_it(): void
    {
        Period::factory()->create(['shift_id' => $this->shift->id, 'number' => 1]);

        $this->as($this->admin)->postJson('/api/periods', $this->payload(['start_time' => '10:00', 'end_time' => '10:45']))
            ->assertUnprocessable()->assertJsonValidationErrors(['number']);

        $this->as($this->admin)->postJson('/api/periods', $this->payload(['shift_id' => Shift::factory()->create()->id]))
            ->assertCreated();
    }

    public function test_overlapping_periods_in_one_shift_are_422(): void
    {
        Period::factory()->create(['shift_id' => $this->shift->id, 'number' => 1, 'start_time' => '08:00:00', 'end_time' => '08:45:00']);

        $this->as($this->admin)->postJson('/api/periods', $this->payload(['number' => 2, 'start_time' => '08:30', 'end_time' => '09:15']))
            ->assertUnprocessable()->assertJsonValidationErrors(['start_time']);
        // Inside another period, and around it.
        $this->as($this->admin)->postJson('/api/periods', $this->payload(['number' => 2, 'start_time' => '08:10', 'end_time' => '08:20']))
            ->assertUnprocessable()->assertJsonValidationErrors(['start_time']);
        $this->as($this->admin)->postJson('/api/periods', $this->payload(['number' => 2, 'start_time' => '07:30', 'end_time' => '09:00']))
            ->assertUnprocessable()->assertJsonValidationErrors(['start_time']);

        // Back to back is fine.
        $this->as($this->admin)->postJson('/api/periods', $this->payload(['number' => 2, 'start_time' => '08:45', 'end_time' => '09:30']))
            ->assertCreated();
    }

    public function test_periods_of_different_shifts_may_overlap(): void
    {
        Period::factory()->create(['shift_id' => $this->shift->id, 'number' => 1, 'start_time' => '08:00:00', 'end_time' => '08:45:00']);

        $this->as($this->admin)->postJson('/api/periods', $this->payload(['shift_id' => Shift::factory()->create()->id]))->assertCreated();
    }

    public function test_the_end_must_be_after_the_start(): void
    {
        $this->as($this->admin)->postJson('/api/periods', $this->payload(['start_time' => '09:00', 'end_time' => '08:00']))
            ->assertUnprocessable()->assertJsonValidationErrors(['end_time']);
    }

    public function test_a_partial_update_is_checked_against_the_saved_times_and_neighbours(): void
    {
        $first = Period::factory()->create(['shift_id' => $this->shift->id, 'number' => 1, 'start_time' => '08:00:00', 'end_time' => '08:45:00']);
        $second = Period::factory()->create(['shift_id' => $this->shift->id, 'number' => 2, 'start_time' => '08:45:00', 'end_time' => '09:30:00']);

        // Only end_time sent; it now runs into the second period.
        $this->as($this->admin)->putJson("/api/periods/{$first->id}", ['end_time' => '09:00'])
            ->assertUnprocessable()->assertJsonValidationErrors(['start_time']);
        // Only start_time sent, after the saved end.
        $this->as($this->admin)->putJson("/api/periods/{$second->id}", ['start_time' => '09:45'])
            ->assertUnprocessable()->assertJsonValidationErrors(['end_time']);
        // Taking another period's number.
        $this->as($this->admin)->putJson("/api/periods/{$second->id}", ['number' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors(['number']);
        // Keeping its own number is fine, and the shift can't change.
        $this->as($this->admin)->putJson("/api/periods/{$second->id}", ['number' => 2, 'name_en' => 'Second'])->assertOk();
        $this->as($this->admin)->putJson("/api/periods/{$second->id}", ['shift_id' => Shift::factory()->create()->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['shift_id']);
    }

    public function test_deleting_a_period_used_by_routine_slots_is_409(): void
    {
        $slot = RoutineSlot::factory()->create(['academic_year_id' => $this->year->id, 'section_id' => $this->section9->id]);

        $this->as($this->admin)->deleteJson("/api/periods/{$slot->period_id}")
            ->assertStatus(409)->assertJsonStructure(['message']);
        $this->assertDatabaseHas('periods', ['id' => $slot->period_id]);
    }

    public function test_a_used_period_cannot_become_a_break(): void
    {
        $slot = RoutineSlot::factory()->create(['academic_year_id' => $this->year->id, 'section_id' => $this->section9->id]);

        $this->as($this->admin)->putJson("/api/periods/{$slot->period_id}", ['is_break' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['is_break']);
    }
}
