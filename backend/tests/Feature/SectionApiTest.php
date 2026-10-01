<?php

namespace Tests\Feature;

use App\Models\Classes;
use App\Models\ClassSection;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StudentEnrolment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionApiTest extends TestCase
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
        $this->getJson('/api/sections')->assertUnauthorized();
    }

    public function test_index_returns_shape_and_filters(): void
    {
        $class = Classes::factory()->create();
        $shift = Shift::factory()->create();
        Section::factory()->create(['class_id' => $class->id, 'shift_id' => $shift->id]);
        Section::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/sections')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'class_id', 'shift_id', 'name', 'code', 'capacity', 'group', 'is_active', 'class', 'shift']],
                'links',
                'meta' => ['total'],
            ])
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/sections?class_id={$class->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_store_creates_a_section_with_group_for_class_9(): void
    {
        $class = Classes::factory()->create(['number' => 9]);
        $shift = Shift::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/sections', [
                'class_id' => $class->id,
                'shift_id' => $shift->id,
                'name' => 'Section A',
                'code' => 'A',
                'group' => 'science',
            ])
            ->assertCreated()
            ->assertJsonPath('data.group', 'science')
            ->assertJsonPath('message', 'Section created successfully');
    }

    public function test_store_requires_shift_id(): void
    {
        $class = Classes::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/sections', ['class_id' => $class->id, 'name' => 'A', 'code' => 'A'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift_id');
    }

    public function test_store_rejects_an_inactive_shift(): void
    {
        $class = Classes::factory()->create();
        $shift = Shift::factory()->create(['is_active' => false]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/sections', ['class_id' => $class->id, 'shift_id' => $shift->id, 'name' => 'A', 'code' => 'A'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift_id');
    }

    public function test_store_rejects_a_group_below_class_9(): void
    {
        $class = Classes::factory()->create(['number' => 8]);
        $shift = Shift::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/sections', [
                'class_id' => $class->id, 'shift_id' => $shift->id, 'name' => 'A', 'code' => 'A', 'group' => 'science',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('group');
    }

    public function test_update_partial_group_addition_is_rejected_below_class_9(): void
    {
        $class = Classes::factory()->create(['number' => 5]);
        $section = Section::factory()->create(['class_id' => $class->id, 'group' => null]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/sections/{$section->id}", ['group' => 'humanities'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('group');
    }

    public function test_duplicate_code_in_same_class_and_shift_is_rejected_but_another_shift_is_ok(): void
    {
        $class = Classes::factory()->create();
        $shiftA = Shift::factory()->create();
        $shiftB = Shift::factory()->create();
        Section::factory()->create(['class_id' => $class->id, 'shift_id' => $shiftA->id, 'code' => 'A']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/sections', [
                'class_id' => $class->id, 'shift_id' => $shiftA->id, 'name' => 'A', 'code' => 'A',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/sections', [
                'class_id' => $class->id, 'shift_id' => $shiftB->id, 'name' => 'A', 'code' => 'A',
            ])
            ->assertCreated();
    }

    public function test_update_rejects_changing_class_id(): void
    {
        $section = Section::factory()->create();
        $otherClass = Classes::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$section->id}", ['class_id' => $otherClass->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('class_id');
    }

    public function test_destroy_returns_no_content(): void
    {
        $section = Section::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/sections/{$section->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($section);
    }

    public function test_destroy_returns_409_when_section_has_students(): void
    {
        $section = Section::factory()->create();
        StudentEnrolment::factory()->create(['section_id' => $section->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/sections/{$section->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Section has students and cannot be deleted.');

        $this->assertNotSoftDeleted($section);
    }

    public function test_destroy_removes_class_teacher_rows_only(): void
    {
        $section = Section::factory()->create();
        $classSection = \App\Models\ClassSection::factory()->create(['section_id' => $section->id, 'class_id' => $section->class_id]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/sections/{$section->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('class_sections', ['id' => $classSection->id]);
    }

    public function test_non_numeric_id_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/sections/1abc')
            ->assertNotFound();
    }

    public function test_teacher_cannot_write_sections(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $section = Section::factory()->create();

        $this->actingAs($teacher, 'sanctum')->postJson('/api/sections', [])->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->putJson("/api/sections/{$section->id}", ['name' => 'Y'])->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->deleteJson("/api/sections/{$section->id}")->assertForbidden();
    }

    public function test_invalid_list_filters_are_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/sections?search[]=x&group=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search', 'group']);
    }

    public function test_lowercase_code_collides_with_uppercase(): void
    {
        $class = Classes::factory()->create();
        $shift = Shift::factory()->create();
        Section::factory()->create(['class_id' => $class->id, 'shift_id' => $shift->id, 'code' => 'A']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/sections', [
                'class_id' => $class->id, 'shift_id' => $shift->id, 'name' => 'a', 'code' => 'a',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_update_of_a_section_on_a_deactivated_shift_works_when_shift_is_unchanged(): void
    {
        $shift = Shift::factory()->create();
        $section = Section::factory()->create(['shift_id' => $shift->id]);
        $shift->update(['is_active' => false]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$section->id}", ['shift_id' => $shift->id, 'name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_update_to_a_different_inactive_shift_is_rejected(): void
    {
        $section = Section::factory()->create();
        $inactive = Shift::factory()->create(['is_active' => false]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$section->id}", ['shift_id' => $inactive->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift_id');
    }

    public function test_changing_shift_is_rejected_when_a_class_teacher_is_not_in_the_new_shift(): void
    {
        $oldShift = Shift::factory()->create();
        $newShift = Shift::factory()->create();
        $section = Section::factory()->create(['shift_id' => $oldShift->id]);
        $staff = Staff::factory()->create(['status' => Staff::STATUS_ACTIVE, 'category' => Staff::CATEGORY_TEACHER]);
        $staff->shifts()->attach($oldShift);
        ClassSection::factory()->create(['section_id' => $section->id, 'class_id' => $section->class_id, 'staff_id' => $staff->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$section->id}", ['shift_id' => $newShift->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shift_id');

        $staff->shifts()->attach($newShift);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$section->id}", ['shift_id' => $newShift->id])
            ->assertOk();
    }
}
