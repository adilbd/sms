<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSection;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassTeacherApiTest extends TestCase
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
        $section = Section::factory()->create();

        $this->getJson("/api/sections/{$section->id}/class-teachers")->assertUnauthorized();
        $this->putJson("/api/sections/{$section->id}/class-teacher", [])->assertUnauthorized();
    }

    public function test_a_role_without_edit_classes_can_read_but_not_assign(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $section = Section::factory()->create();
        $year = AcademicYear::factory()->create();

        $this->actingAs($teacher, 'sanctum')->getJson("/api/sections/{$section->id}/class-teachers")->assertOk();
        $this->actingAs($teacher, 'sanctum')
            ->putJson("/api/sections/{$section->id}/class-teacher", ['academic_year_id' => $year->id, 'staff_id' => null])
            ->assertForbidden();
    }

    public function test_assigning_an_active_teacher_from_the_sections_shift_returns_200(): void
    {
        $shift = Shift::factory()->create();
        $section = Section::factory()->create(['shift_id' => $shift->id]);
        $year = AcademicYear::factory()->create();
        $staff = Staff::factory()->create(['status' => Staff::STATUS_ACTIVE, 'category' => Staff::CATEGORY_TEACHER]);
        $staff->shifts()->attach($shift);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$section->id}/class-teacher", [
                'academic_year_id' => $year->id, 'staff_id' => $staff->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.staff.id', $staff->id)
            ->assertJsonPath('data.section_id', $section->id)
            ->assertJsonPath('data.academic_year_id', $year->id);

        $this->assertDatabaseHas('class_sections', [
            'section_id' => $section->id, 'academic_year_id' => $year->id, 'staff_id' => $staff->id,
        ]);
    }

    public function test_index_lists_one_row_per_year(): void
    {
        $section = Section::factory()->create();
        $yearA = AcademicYear::factory()->create();
        $yearB = AcademicYear::factory()->create();
        ClassSection::factory()->create(['section_id' => $section->id, 'academic_year_id' => $yearA->id]);
        ClassSection::factory()->create(['section_id' => $section->id, 'academic_year_id' => $yearB->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/sections/{$section->id}/class-teachers")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_rejects_a_non_teacher(): void
    {
        $shift = Shift::factory()->create();
        $section = Section::factory()->create(['shift_id' => $shift->id]);
        $year = AcademicYear::factory()->create();
        $staff = Staff::factory()->create(['category' => Staff::CATEGORY_STAFF, 'position' => Staff::POSITION_STAFF]);
        $staff->shifts()->attach($shift);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$section->id}/class-teacher", ['academic_year_id' => $year->id, 'staff_id' => $staff->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff_id');
    }

    public function test_rejects_an_inactive_teacher(): void
    {
        $shift = Shift::factory()->create();
        $section = Section::factory()->create(['shift_id' => $shift->id]);
        $year = AcademicYear::factory()->create();
        $staff = Staff::factory()->create(['status' => Staff::STATUS_RETIRED, 'leaving_date' => '2020-01-01']);
        $staff->shifts()->attach($shift);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$section->id}/class-teacher", ['academic_year_id' => $year->id, 'staff_id' => $staff->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff_id');
    }

    public function test_rejects_a_teacher_not_in_the_sections_shift(): void
    {
        $sectionShift = Shift::factory()->create();
        $otherShift = Shift::factory()->create();
        $section = Section::factory()->create(['shift_id' => $sectionShift->id]);
        $year = AcademicYear::factory()->create();
        $staff = Staff::factory()->create();
        $staff->shifts()->attach($otherShift);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$section->id}/class-teacher", ['academic_year_id' => $year->id, 'staff_id' => $staff->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff_id');
    }

    public function test_rejects_the_same_teacher_leading_a_second_section_in_the_same_year(): void
    {
        $shift = Shift::factory()->create();
        $sectionA = Section::factory()->create(['shift_id' => $shift->id]);
        $sectionB = Section::factory()->create(['shift_id' => $shift->id]);
        $year = AcademicYear::factory()->create();
        $staff = Staff::factory()->create();
        $staff->shifts()->attach($shift);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$sectionA->id}/class-teacher", ['academic_year_id' => $year->id, 'staff_id' => $staff->id])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$sectionB->id}/class-teacher", ['academic_year_id' => $year->id, 'staff_id' => $staff->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff_id');
    }

    public function test_staff_id_null_unassigns_and_is_a_no_op_when_nothing_is_assigned(): void
    {
        $section = Section::factory()->create();
        $year = AcademicYear::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$section->id}/class-teacher", ['academic_year_id' => $year->id, 'staff_id' => null])
            ->assertOk()
            ->assertJsonPath('data.staff', null);

        $shift = Shift::factory()->create();
        $section2 = Section::factory()->create(['shift_id' => $shift->id]);
        $staff = Staff::factory()->create();
        $staff->shifts()->attach($shift);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$section2->id}/class-teacher", ['academic_year_id' => $year->id, 'staff_id' => $staff->id])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$section2->id}/class-teacher", ['academic_year_id' => $year->id, 'staff_id' => null])
            ->assertOk()
            ->assertJsonPath('data.staff', null);

        $this->assertDatabaseMissing('class_sections', ['section_id' => $section2->id, 'academic_year_id' => $year->id]);
    }

    public function test_non_numeric_section_id_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/sections/1abc/class-teachers')
            ->assertNotFound();
    }

    public function test_response_carries_only_a_narrow_staff_shape_for_a_teacher_role_user(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $section = Section::factory()->create();
        $staff = Staff::factory()->create([
            'nid' => '1234567890123', 'mpo_index' => 'MPO-1', 'mobile' => '01700000000',
            'present_address' => 'Somewhere', 'permanent_address' => 'Elsewhere', 'date_of_birth' => '1985-01-01',
        ]);
        ClassSection::factory()->create(['section_id' => $section->id, 'class_id' => $section->class_id, 'staff_id' => $staff->id]);

        $response = $this->actingAs($teacher, 'sanctum')
            ->getJson("/api/sections/{$section->id}/class-teachers")
            ->assertOk()
            ->assertJsonPath('data.0.staff.id', $staff->id);

        $this->assertEqualsCanonicalizing(
            ['id', 'name_en', 'name_bn', 'designation', 'photo_url'],
            array_keys($response->json('data.0.staff'))
        );
        $this->assertStringNotContainsString('1234567890123', $response->getContent());
        $this->assertStringNotContainsString('MPO-1', $response->getContent());
    }

    public function test_assign_rejects_a_soft_deleted_academic_year(): void
    {
        $section = Section::factory()->create();
        $year = AcademicYear::factory()->create();
        $year->delete();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$section->id}/class-teacher", ['academic_year_id' => $year->id, 'staff_id' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('academic_year_id');
    }

    public function test_assign_rejects_a_soft_deleted_staff_member(): void
    {
        $section = Section::factory()->create();
        $year = AcademicYear::factory()->create();
        $staff = Staff::factory()->create();
        $staff->delete();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$section->id}/class-teacher", ['academic_year_id' => $year->id, 'staff_id' => $staff->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('staff_id');
    }
}
