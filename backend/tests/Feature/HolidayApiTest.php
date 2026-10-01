<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Holiday;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExams;
use Tests\TestCase;

class HolidayApiTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpExams();
    }

    public function test_an_admin_creates_a_holiday_and_the_year_comes_from_the_date(): void
    {
        $this->as($this->admin)->postJson('/api/holidays', ['date' => '2026-02-21', 'name_en' => 'Shaheed Dibosh', 'name_bn' => 'শহীদ দিবস'])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'date', 'name_en', 'name_bn', 'academic_year_id', 'created_at', 'updated_at'], 'message'])
            ->assertJsonPath('data.date', '2026-02-21')
            ->assertJsonPath('data.academic_year_id', $this->year->id);

        $this->assertDatabaseHas('holidays', ['date' => '2026-02-21', 'academic_year_id' => $this->year->id]);
    }

    public function test_one_name_is_enough_and_at_least_one_is_required(): void
    {
        $this->as($this->admin)->postJson('/api/holidays', ['date' => '2026-03-26', 'name_bn' => 'স্বাধীনতা দিবস'])->assertCreated();

        $this->as($this->admin)->postJson('/api/holidays', ['date' => '2026-05-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['name_en']);
        $this->as($this->admin)->postJson('/api/holidays', ['date' => '2026-05-01', 'name_en' => '', 'name_bn' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['name_en']);
    }

    public function test_the_payload_is_validated(): void
    {
        $existing = Holiday::factory()->create(['date' => '2026-02-21', 'academic_year_id' => $this->year->id]);

        $this->as($this->admin)->postJson('/api/holidays', [])->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->as($this->admin)->postJson('/api/holidays', ['date' => '21/02/2026', 'name_en' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->as($this->admin)->postJson('/api/holidays', ['date' => '2026-02-21', 'name_en' => 'Duplicate'])->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->as($this->admin)->postJson('/api/holidays', ['date' => '2026-02-22', 'name_en' => str_repeat('x', 256)])->assertUnprocessable()->assertJsonValidationErrors(['name_en']);

        // No academic year covers 2030, or the days outside the year's own dates.
        $this->as($this->admin)->postJson('/api/holidays', ['date' => '2030-02-21', 'name_en' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->year->update(['start_date' => '2026-02-01']);
        $this->as($this->admin)->postJson('/api/holidays', ['date' => '2026-01-15', 'name_en' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['date']);

        // Updating keeps its own date but not another holiday's.
        $other = Holiday::factory()->create(['date' => '2026-03-26', 'academic_year_id' => $this->year->id]);
        $this->as($this->admin)->putJson("/api/holidays/{$other->id}", ['date' => '2026-02-21'])->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->as($this->admin)->putJson("/api/holidays/{$existing->id}", ['date' => '2026-02-21'])->assertOk();
    }

    public function test_per_page_is_limited_to_100(): void
    {
        $this->as($this->admin)->getJson('/api/holidays?per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->as($this->admin)->getJson('/api/holidays?per_page=0')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->as($this->admin)->getJson('/api/holidays?per_page=100')->assertOk();
    }

    public function test_index_lists_by_date_and_filters_by_academic_year(): void
    {
        $other = AcademicYear::factory()->create(['year' => 2027]);
        Holiday::factory()->create(['date' => '2026-12-16', 'academic_year_id' => $this->year->id]);
        Holiday::factory()->create(['date' => '2026-02-21', 'academic_year_id' => $this->year->id]);
        Holiday::factory()->create(['date' => '2027-02-21', 'academic_year_id' => $other->id]);

        $all = $this->as($this->admin)->getJson('/api/holidays')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'date', 'name_en', 'name_bn', 'academic_year_id']], 'links', 'meta' => ['total']]);
        $this->assertSame(['2026-02-21', '2026-12-16', '2027-02-21'], array_column($all->json('data'), 'date'));

        $filtered = $this->as($this->admin)->getJson("/api/holidays?academic_year_id={$this->year->id}")->assertOk();
        $this->assertSame(['2026-02-21', '2026-12-16'], array_column($filtered->json('data'), 'date'));

        $this->as($this->admin)->getJson('/api/holidays?academic_year_id[]=1')->assertUnprocessable();
    }

    public function test_show_update_and_delete(): void
    {
        $holiday = Holiday::factory()->create(['date' => '2026-02-21', 'name_en' => 'Old', 'name_bn' => null, 'academic_year_id' => $this->year->id]);

        $this->as($this->admin)->getJson("/api/holidays/{$holiday->id}")->assertOk()->assertJsonPath('data.name_en', 'Old');

        $this->as($this->admin)->putJson("/api/holidays/{$holiday->id}", ['name_en' => 'New', 'date' => '2026-02-22'])
            ->assertOk()->assertJsonPath('data.name_en', 'New')->assertJsonPath('data.date', '2026-02-22');

        // A partial update that clears the only name is refused.
        $this->as($this->admin)->putJson("/api/holidays/{$holiday->id}", ['name_en' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['name_en']);

        $this->as($this->admin)->deleteJson("/api/holidays/{$holiday->id}")->assertNoContent();
        $this->assertDatabaseMissing('holidays', ['id' => $holiday->id]);

        $this->as($this->admin)->getJson("/api/holidays/{$holiday->id}")->assertNotFound();
        $this->as($this->admin)->putJson('/api/holidays/999999', ['name_en' => 'X'])->assertNotFound();
        $this->as($this->admin)->deleteJson('/api/holidays/1abc')->assertNotFound();
    }

    public function test_teachers_can_read_but_only_settings_admins_can_change(): void
    {
        $holiday = Holiday::factory()->create(['academic_year_id' => $this->year->id, 'date' => '2026-02-21']);
        $teacher = $this->userWithRole('teacher');

        $this->as($teacher)->getJson('/api/holidays')->assertOk();
        $this->as($teacher)->getJson("/api/holidays/{$holiday->id}")->assertOk();
        $this->as($teacher)->postJson('/api/holidays', ['date' => '2026-03-26', 'name_en' => 'X'])->assertForbidden();
        $this->as($teacher)->putJson("/api/holidays/{$holiday->id}", ['name_en' => 'X'])->assertForbidden();
        $this->as($teacher)->deleteJson("/api/holidays/{$holiday->id}")->assertForbidden();

        foreach (['student', 'parent', 'office'] as $role) {
            $this->as($this->userWithRole($role))->getJson('/api/holidays')->assertForbidden();
        }
    }

    public function test_guests_get_401(): void
    {
        $this->getJson('/api/holidays')->assertUnauthorized();
        $this->postJson('/api/holidays', [])->assertUnauthorized();
    }

    public function test_an_academic_year_with_holidays_cannot_be_deleted(): void
    {
        $year = AcademicYear::factory()->create(['year' => 2027]);
        Holiday::factory()->create(['date' => '2027-02-21', 'academic_year_id' => $year->id]);

        $this->as($this->admin)->deleteJson("/api/academic-years/{$year->id}")
            ->assertStatus(409)->assertJsonPath('message', 'Academic year has holidays and cannot be deleted.');
    }
}
