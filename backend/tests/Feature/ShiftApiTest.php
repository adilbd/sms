<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@sms.com')->firstOrFail();
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/shifts')->assertUnauthorized();
    }

    public function test_any_signed_in_user_can_read_shifts_but_needs_edit_settings_to_write(): void
    {
        Shift::factory()->create();
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');

        $this->actingAs($teacher, 'sanctum')->getJson('/api/shifts')->assertOk();
        $this->actingAs($teacher, 'sanctum')->postJson('/api/shifts', ['name_en' => 'X', 'name_bn' => 'X', 'slug' => 'x'])->assertForbidden();

        $shift = Shift::first();
        $this->actingAs($teacher, 'sanctum')->putJson("/api/shifts/{$shift->id}", ['name_en' => 'Y'])->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->deleteJson("/api/shifts/{$shift->id}")->assertForbidden();
    }

    public function test_crud_shapes(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')->postJson('/api/shifts', [
            'name_en' => 'Morning', 'name_bn' => 'প্রভাতি', 'slug' => 'morning', 'start_time' => '07:00', 'end_time' => '12:00',
        ])->assertCreated()->assertJsonStructure(['data' => ['id', 'name_en', 'name_bn', 'slug', 'start_time', 'end_time', 'is_active']]);

        $id = $response->json('data.id');

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/shifts/{$id}")->assertOk()->assertJsonPath('data.slug', 'morning');

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/shifts/{$id}", ['name_en' => 'Morning Shift'])
            ->assertOk()->assertJsonPath('data.name_en', 'Morning Shift');

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/shifts/{$id}")->assertNoContent();
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        Shift::factory()->create(['slug' => 'morning']);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/shifts', [
            'name_en' => 'Morning', 'name_bn' => 'প্রভাতি', 'slug' => 'morning',
        ])->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_deleting_a_shift_with_staff_is_a_conflict(): void
    {
        $shift = Shift::factory()->create();
        $staff = Staff::factory()->create();
        $staff->shifts()->attach($shift);

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/shifts/{$shift->id}")->assertStatus(409);

        $this->assertDatabaseHas('shifts', ['id' => $shift->id]);
    }
}
