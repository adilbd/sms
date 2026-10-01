<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\StudentEnrolment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AcademicYearApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@sms.com')->firstOrFail();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/academic-years')->assertUnauthorized();
    }

    public function test_any_signed_in_user_can_read_but_needs_edit_settings_to_write(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');

        $this->actingAs($student, 'sanctum')->getJson('/api/academic-years')->assertOk();
        $this->actingAs($student, 'sanctum')->postJson('/api/academic-years', ['year' => 2030])->assertForbidden();

        $year = AcademicYear::factory()->create();
        $this->actingAs($student, 'sanctum')->putJson("/api/academic-years/{$year->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($student, 'sanctum')->deleteJson("/api/academic-years/{$year->id}")->assertForbidden();
        $this->actingAs($student, 'sanctum')->postJson("/api/academic-years/{$year->id}/activate")->assertForbidden();
    }

    public function test_store_defaults_dates_to_the_calendar_year(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/academic-years', ['year' => 2027, 'name' => '2027'])
            ->assertCreated()
            ->assertJsonPath('data.year', 2027)
            ->assertJsonPath('data.start_date', '2027-01-01')
            ->assertJsonPath('data.end_date', '2027-12-31')
            ->assertJsonPath('data.code', '2027');
    }

    public function test_store_rejects_dates_outside_the_year(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/academic-years', ['year' => 2027, 'name' => '2027', 'start_date' => '2026-12-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('start_date');
    }

    public function test_store_rejects_end_before_start(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/academic-years', [
                'year' => 2027, 'name' => '2027', 'start_date' => '2027-06-01', 'end_date' => '2027-01-01',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');
    }

    public function test_update_rejects_end_date_before_saved_start_date(): void
    {
        $year = AcademicYear::factory()->create(['year' => 2028, 'start_date' => '2028-03-01', 'end_date' => '2028-12-31']);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/academic-years/{$year->id}", ['end_date' => '2028-02-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('end_date');
    }

    public function test_store_rejects_duplicate_year(): void
    {
        AcademicYear::factory()->create(['year' => 2029]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/academic-years', ['year' => 2029, 'name' => '2029 Again'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('year');
    }

    public function test_activate_deactivates_others(): void
    {
        $current = AcademicYear::factory()->active()->create(['year' => 2026]);
        $next = AcademicYear::factory()->create(['year' => 2027]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/academic-years/{$next->id}/activate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertFalse($current->fresh()->is_active);
        $this->assertTrue($next->fresh()->is_active);
    }

    public function test_destroy_returns_no_content_for_an_unused_inactive_year(): void
    {
        $year = AcademicYear::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/academic-years/{$year->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($year);
    }

    public function test_destroy_returns_409_when_year_is_active(): void
    {
        $year = AcademicYear::factory()->active()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/academic-years/{$year->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Academic year is active and cannot be deleted.');
    }

    public function test_destroy_returns_409_when_year_is_referenced_by_students(): void
    {
        $year = AcademicYear::factory()->create();
        StudentEnrolment::factory()->create(['academic_year_id' => $year->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/academic-years/{$year->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Academic year has students and cannot be deleted.');
    }

    public function test_non_numeric_id_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/academic-years/1abc')
            ->assertNotFound();
    }

    public function test_update_rejects_explicit_null_for_not_null_columns(): void
    {
        $year = AcademicYear::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/academic-years/{$year->id}", ['code' => null, 'start_date' => null, 'end_date' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code', 'start_date', 'end_date']);
    }

    public function test_seeder_does_not_leave_two_active_years_and_is_idempotent(): void
    {
        $other = AcademicYear::factory()->create(['year' => 2025, 'is_active' => true]);

        $this->seed(\Database\Seeders\AcademicYearSeeder::class);
        $this->seed(\Database\Seeders\AcademicYearSeeder::class);

        $this->assertSame(1, AcademicYear::where('year', 2026)->count());
        $this->assertFalse(AcademicYear::where('year', 2026)->first()->is_active);
        $this->assertTrue($other->fresh()->is_active);
    }
}
