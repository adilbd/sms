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

    private function teacherIn(Shift $shift, array $attributes = []): Staff
    {
        $staff = Staff::factory()->create([...['status' => Staff::STATUS_ACTIVE, 'category' => Staff::CATEGORY_TEACHER], ...$attributes]);
        $staff->shifts()->attach($shift);

        return $staff;
    }

    private function replace(Section $section, AcademicYear $year, array $teachers, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin, 'sanctum')
            ->putJson("/api/sections/{$section->id}/class-teachers", ['academic_year_id' => $year->id, 'teachers' => $teachers]);
    }

    public function test_requires_authentication(): void
    {
        $section = Section::factory()->create();

        $this->getJson("/api/sections/{$section->id}/class-teachers")->assertUnauthorized();
        $this->putJson("/api/sections/{$section->id}/class-teachers", [])->assertUnauthorized();
    }

    public function test_the_single_teacher_route_is_gone(): void
    {
        $section = Section::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/sections/{$section->id}/class-teacher", [])
            ->assertNotFound();
    }

    public function test_a_role_without_edit_classes_can_read_but_not_assign(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $section = Section::factory()->create();
        $year = AcademicYear::factory()->create();

        $this->actingAs($teacher, 'sanctum')->getJson("/api/sections/{$section->id}/class-teachers")->assertOk();
        $this->replace($section, $year, [], $teacher)->assertForbidden();
    }

    public function test_one_main_and_two_co_teachers_save(): void
    {
        $shift = Shift::factory()->create();
        $section = Section::factory()->create(['shift_id' => $shift->id]);
        $year = AcademicYear::factory()->create();
        [$main, $co1, $co2] = [$this->teacherIn($shift), $this->teacherIn($shift), $this->teacherIn($shift)];

        $this->replace($section, $year, [
            ['staff_id' => $co1->id, 'is_main' => false],
            ['staff_id' => $main->id, 'is_main' => true],
            ['staff_id' => $co2->id, 'is_main' => false],
        ])
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.staff_id', $main->id)
            ->assertJsonPath('data.0.is_main', true)
            ->assertJsonPath('data.1.is_main', false)
            ->assertJsonPath('data.0.section_id', $section->id)
            ->assertJsonPath('data.0.academic_year_id', $year->id)
            ->assertJsonPath('message', 'Class teachers updated successfully');

        $this->assertDatabaseHas('class_sections', ['section_id' => $section->id, 'staff_id' => $main->id, 'is_main' => true]);
        $this->assertSame(1, ClassSection::where('section_id', $section->id)->where('is_main', true)->count());
    }

    public function test_the_put_replaces_the_whole_list_and_may_move_the_main_flag(): void
    {
        $shift = Shift::factory()->create();
        $section = Section::factory()->create(['shift_id' => $shift->id]);
        $year = AcademicYear::factory()->create();
        [$a, $b, $c] = [$this->teacherIn($shift), $this->teacherIn($shift), $this->teacherIn($shift)];

        $this->replace($section, $year, [['staff_id' => $a->id, 'is_main' => true], ['staff_id' => $b->id, 'is_main' => false]])->assertOk();
        $this->replace($section, $year, [['staff_id' => $b->id, 'is_main' => true], ['staff_id' => $c->id, 'is_main' => false]])
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.staff_id', $b->id);

        $this->assertDatabaseMissing('class_sections', ['section_id' => $section->id, 'staff_id' => $a->id]);

        $this->replace($section, $year, [])->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseMissing('class_sections', ['section_id' => $section->id, 'academic_year_id' => $year->id]);
    }

    public function test_two_mains_or_no_main_gives_422(): void
    {
        $shift = Shift::factory()->create();
        $section = Section::factory()->create(['shift_id' => $shift->id]);
        $year = AcademicYear::factory()->create();
        [$a, $b] = [$this->teacherIn($shift), $this->teacherIn($shift)];

        $this->replace($section, $year, [['staff_id' => $a->id, 'is_main' => true], ['staff_id' => $b->id, 'is_main' => true]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teachers.1.is_main']);

        $this->replace($section, $year, [['staff_id' => $a->id, 'is_main' => false], ['staff_id' => $b->id, 'is_main' => false]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teachers']);

        $this->assertDatabaseCount('class_sections', 0);
    }

    public function test_a_teacher_outside_the_shift_or_inactive_is_reported_on_their_row(): void
    {
        $shift = Shift::factory()->create();
        $section = Section::factory()->create(['shift_id' => $shift->id]);
        $year = AcademicYear::factory()->create();
        $ok = $this->teacherIn($shift);
        $otherShift = $this->teacherIn(Shift::factory()->create());
        $retired = $this->teacherIn($shift, ['status' => Staff::STATUS_RETIRED, 'leaving_date' => '2020-01-01']);
        $office = $this->teacherIn($shift, ['category' => Staff::CATEGORY_STAFF, 'position' => Staff::POSITION_STAFF]);

        $this->replace($section, $year, [
            ['staff_id' => $ok->id, 'is_main' => true],
            ['staff_id' => $otherShift->id, 'is_main' => false],
            ['staff_id' => $retired->id, 'is_main' => false],
            ['staff_id' => $office->id, 'is_main' => false],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['teachers.1.staff_id', 'teachers.2.staff_id', 'teachers.3.staff_id'])
            ->assertJsonMissingValidationErrors(['teachers.0.staff_id']);

        $this->assertDatabaseCount('class_sections', 0);
    }

    public function test_one_teacher_can_lead_two_sections_in_a_year(): void
    {
        $shift = Shift::factory()->create();
        $sectionA = Section::factory()->create(['shift_id' => $shift->id]);
        $sectionB = Section::factory()->create(['shift_id' => $shift->id]);
        $year = AcademicYear::factory()->create();
        $staff = $this->teacherIn($shift);

        $this->replace($sectionA, $year, [['staff_id' => $staff->id, 'is_main' => true]])->assertOk();
        $this->replace($sectionB, $year, [['staff_id' => $staff->id, 'is_main' => true]])->assertOk();

        $this->assertSame(2, ClassSection::where('academic_year_id', $year->id)->where('staff_id', $staff->id)->count());
    }

    public function test_validates_the_payload_shape(): void
    {
        $section = Section::factory()->create();
        $year = AcademicYear::factory()->create();
        $staff = Staff::factory()->create();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/sections/{$section->id}/class-teachers", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['academic_year_id', 'teachers']);

        $this->replace($section, $year, [['staff_id' => $staff->id], ['staff_id' => $staff->id, 'is_main' => true]])
            ->assertUnprocessable()->assertJsonValidationErrors(['teachers.0.is_main', 'teachers.0.staff_id']);

        $this->replace($section, $year, [['staff_id' => 99999, 'is_main' => true]])
            ->assertUnprocessable()->assertJsonValidationErrors(['teachers.0.staff_id']);
    }

    public function test_put_to_an_unknown_section_returns_404(): void
    {
        $year = AcademicYear::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/sections/9999/class-teachers', ['academic_year_id' => $year->id, 'teachers' => []])
            ->assertNotFound();
    }

    public function test_index_lists_every_teacher_of_every_year_main_first(): void
    {
        $section = Section::factory()->create();
        $yearA = AcademicYear::factory()->create();
        $yearB = AcademicYear::factory()->create();
        ClassSection::factory()->create(['section_id' => $section->id, 'academic_year_id' => $yearA->id, 'staff_id' => Staff::factory(), 'is_main' => false]);
        ClassSection::factory()->create(['section_id' => $section->id, 'academic_year_id' => $yearA->id, 'staff_id' => Staff::factory(), 'is_main' => true]);
        ClassSection::factory()->create(['section_id' => $section->id, 'academic_year_id' => $yearB->id, 'staff_id' => Staff::factory(), 'is_main' => true]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/sections/{$section->id}/class-teachers")
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['section_id', 'academic_year_id', 'staff_id', 'is_main', 'staff' => ['id', 'name_en']]]])
            ->assertJsonPath('data.0.academic_year_id', $yearB->id)
            ->assertJsonPath('data.1.is_main', true)
            ->assertJsonPath('data.2.is_main', false);
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

    public function test_rejects_a_soft_deleted_academic_year_and_staff_member(): void
    {
        $section = Section::factory()->create();
        $year = AcademicYear::factory()->create();
        $staff = Staff::factory()->create();
        $staff->delete();

        $this->replace($section, $year, [['staff_id' => $staff->id, 'is_main' => true]])
            ->assertUnprocessable()->assertJsonValidationErrors('teachers.0.staff_id');

        $year->delete();
        $this->replace($section, $year, [])->assertUnprocessable()->assertJsonValidationErrors('academic_year_id');
    }
}
