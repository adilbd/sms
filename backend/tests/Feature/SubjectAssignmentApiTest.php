<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Subject;
use App\Models\SubjectAssignment;
use App\Models\User;
use App\Services\SubjectAssignmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubjectAssignmentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Shift $shift;

    private Classes $class;

    private Section $section;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@sms.com')->firstOrFail();
        $this->shift = Shift::factory()->create();
        $this->class = Classes::factory()->create(['number' => 9]);
        $this->section = Section::factory()->create(['class_id' => $this->class->id, 'shift_id' => $this->shift->id]);
        $this->year = AcademicYear::factory()->active()->create();
    }

    private function teacher(array $attributes = [], ?Shift $shift = null): Staff
    {
        $staff = Staff::factory()->create($attributes);
        $staff->shifts()->attach($shift ?? $this->shift);

        return $staff;
    }

    private function subjectInCurriculum(): Subject
    {
        $subject = Subject::factory()->create();
        ClassSubject::factory()->create(['class_id' => $this->class->id, 'subject_id' => $subject->id]);

        return $subject;
    }

    private function as(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function payload(Subject $subject, Staff $staff, array $extra = []): array
    {
        return ['section_id' => $this->section->id, 'subject_id' => $subject->id, 'staff_id' => $staff->id, ...$extra];
    }

    public function test_store_creates_an_assignment_for_the_active_year_and_fills_the_class(): void
    {
        $subject = $this->subjectInCurriculum();
        $staff = $this->teacher();

        $this->as($this->admin)->postJson('/api/subject-assignments', $this->payload($subject, $staff))
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'staff_id', 'subject_id', 'class_id', 'section_id', 'academic_year_id', 'staff' => ['id', 'name_en'], 'subject' => ['id', 'name'], 'section' => ['id']], 'message'])
            ->assertJsonPath('data.class_id', $this->class->id)
            ->assertJsonPath('data.academic_year_id', $this->year->id);

        $this->assertDatabaseHas('subject_assignments', ['section_id' => $this->section->id, 'staff_id' => $staff->id, 'class_id' => $this->class->id]);
    }

    public function test_show_update_and_destroy(): void
    {
        $assignment = SubjectAssignment::factory()->create([
            'class_id' => $this->class->id, 'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id, 'subject_id' => $this->subjectInCurriculum()->id,
            'staff_id' => $this->teacher()->id,
        ]);
        $other = $this->teacher();

        $this->as($this->admin)->getJson("/api/subject-assignments/{$assignment->id}")->assertOk()->assertJsonPath('data.id', $assignment->id);

        $this->as($this->admin)->putJson("/api/subject-assignments/{$assignment->id}", ['staff_id' => $other->id])
            ->assertOk()->assertJsonPath('data.staff_id', $other->id);

        $this->as($this->admin)->deleteJson("/api/subject-assignments/{$assignment->id}")->assertNoContent();
        $this->assertDatabaseMissing('subject_assignments', ['id' => $assignment->id]);
    }

    public function test_update_refuses_to_move_the_assignment(): void
    {
        $assignment = SubjectAssignment::factory()->create([
            'class_id' => $this->class->id, 'section_id' => $this->section->id,
            'academic_year_id' => $this->year->id, 'staff_id' => $this->teacher()->id,
        ]);

        $this->as($this->admin)->putJson("/api/subject-assignments/{$assignment->id}", ['staff_id' => $assignment->staff_id, 'section_id' => $this->section->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id']);
    }

    public function test_store_validates_required_fields(): void
    {
        $this->as($this->admin)->postJson('/api/subject-assignments', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id', 'subject_id', 'staff_id']);
    }

    public function test_a_non_teacher_inactive_teacher_or_other_shift_teacher_is_rejected(): void
    {
        $subject = $this->subjectInCurriculum();

        $cases = [
            $this->teacher(['category' => Staff::CATEGORY_STAFF, 'position' => Staff::POSITION_STAFF]),
            $this->teacher(['status' => Staff::STATUS_RESIGNED, 'leaving_date' => '2026-02-01']),
            $this->teacher([], Shift::factory()->create()),
        ];

        foreach ($cases as $staff) {
            $this->as($this->admin)->postJson('/api/subject-assignments', $this->payload($subject, $staff))
                ->assertUnprocessable()->assertJsonValidationErrors(['staff_id']);
        }

        $this->assertDatabaseCount('subject_assignments', 0);
    }

    public function test_a_subject_outside_the_class_curriculum_is_rejected(): void
    {
        $subject = Subject::factory()->create();
        ClassSubject::factory()->create(['subject_id' => $subject->id, 'class_id' => Classes::factory()->create(['number' => 3])->id]); // another class

        $this->as($this->admin)->postJson('/api/subject-assignments', $this->payload($subject, $this->teacher()))
            ->assertUnprocessable()->assertJsonValidationErrors(['subject_id']);
    }

    public function test_a_duplicate_section_subject_year_is_rejected_even_for_another_teacher(): void
    {
        $subject = $this->subjectInCurriculum();
        $this->as($this->admin)->postJson('/api/subject-assignments', $this->payload($subject, $this->teacher()))->assertCreated();

        $this->as($this->admin)->postJson('/api/subject-assignments', $this->payload($subject, $this->teacher()))
            ->assertUnprocessable()->assertJsonValidationErrors(['subject_id']);

        // Another year is a different assignment.
        $next = AcademicYear::factory()->create();
        $this->as($this->admin)->postJson('/api/subject-assignments', $this->payload($subject, $this->teacher(), ['academic_year_id' => $next->id]))->assertCreated();
    }

    public function test_no_active_year_is_a_422_on_academic_year_id(): void
    {
        AcademicYear::query()->update(['is_active' => false]);
        $subject = $this->subjectInCurriculum();

        $this->as($this->admin)->postJson('/api/subject-assignments', $this->payload($subject, $this->teacher()))
            ->assertUnprocessable()->assertJsonValidationErrors(['academic_year_id']);
    }

    public function test_index_is_filtered_and_defaults_to_the_active_year(): void
    {
        $other = Section::factory()->create(['class_id' => $this->class->id, 'shift_id' => $this->shift->id]);
        $oldYear = AcademicYear::factory()->create();
        $make = fn (Section $s, AcademicYear $y) => SubjectAssignment::factory()->create([
            'class_id' => $s->class_id, 'section_id' => $s->id, 'academic_year_id' => $y->id,
        ]);
        $mine = $make($this->section, $this->year);
        $make($other, $this->year);
        $make($this->section, $oldYear);

        $this->as($this->admin)->getJson('/api/subject-assignments')
            ->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta' => ['total']])
            ->assertJsonCount(2, 'data');

        $this->as($this->admin)->getJson("/api/subject-assignments?section_id={$this->section->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);

        $this->as($this->admin)->getJson("/api/subject-assignments?section_id={$this->section->id}&academic_year_id={$oldYear->id}")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->as($this->admin)->getJson("/api/subject-assignments?staff_id={$mine->staff_id}&subject_id={$mine->subject_id}&class_id={$this->class->id}")
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_bad_filter_is_a_422(): void
    {
        $this->as($this->admin)->getJson('/api/subject-assignments?section_id[]=x')
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id']);
    }

    public function test_a_non_numeric_id_and_an_unknown_id_return_404(): void
    {
        $this->as($this->admin)->getJson('/api/subject-assignments/1abc')->assertNotFound();
        $this->as($this->admin)->getJson('/api/subject-assignments/99999')->assertNotFound();
    }

    public function test_authorization(): void
    {
        $this->getJson('/api/subject-assignments')->assertUnauthorized();
        $this->postJson('/api/subject-assignments', [])->assertUnauthorized();

        $assignment = SubjectAssignment::factory()->create([
            'class_id' => $this->class->id, 'section_id' => $this->section->id, 'academic_year_id' => $this->year->id,
        ]);
        $subject = $this->subjectInCurriculum();
        $staff = $this->teacher();

        $teacher = $this->userWithRole('teacher');
        $this->as($teacher)->getJson('/api/subject-assignments')->assertOk();
        $this->as($teacher)->getJson("/api/subject-assignments/{$assignment->id}")->assertOk();
        $this->as($teacher)->postJson('/api/subject-assignments', $this->payload($subject, $staff))->assertForbidden();
        $this->as($teacher)->putJson("/api/subject-assignments/{$assignment->id}", ['staff_id' => $staff->id])->assertForbidden();
        $this->as($teacher)->deleteJson("/api/subject-assignments/{$assignment->id}")->assertForbidden();
        $this->as($teacher)->putJson("/api/sections/{$this->section->id}/subject-teachers", ['academic_year_id' => $this->year->id, 'assignments' => []])->assertForbidden();

        foreach (['student', 'parent'] as $role) {
            $user = $this->userWithRole($role);
            $this->as($user)->getJson('/api/subject-assignments')->assertForbidden();
            $this->as($user)->postJson('/api/subject-assignments', [])->assertForbidden();
            $this->as($user)->putJson("/api/sections/{$this->section->id}/subject-teachers", [])->assertForbidden();
        }

        $this->assertDatabaseHas('subject_assignments', ['id' => $assignment->id]);
    }

    // The bulk endpoint

    public function test_bulk_assigns_three_subjects_and_returns_the_list(): void
    {
        $subjects = [$this->subjectInCurriculum(), $this->subjectInCurriculum(), $this->subjectInCurriculum()];
        $staff = $this->teacher();

        $this->as($this->admin)->putJson("/api/sections/{$this->section->id}/subject-teachers", [
            'academic_year_id' => $this->year->id,
            'assignments' => array_map(fn ($s) => ['subject_id' => $s->id, 'staff_id' => $staff->id], $subjects),
        ])
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure(['data' => [['id', 'staff_id', 'subject_id', 'section_id']], 'message']);

        $this->assertSame(3, SubjectAssignment::where('section_id', $this->section->id)->count());
    }

    public function test_bulk_replaces_changes_and_removes_with_null(): void
    {
        [$a, $b, $c] = [$this->subjectInCurriculum(), $this->subjectInCurriculum(), $this->subjectInCurriculum()];
        $first = $this->teacher();
        $second = $this->teacher();
        $url = "/api/sections/{$this->section->id}/subject-teachers";

        $this->as($this->admin)->putJson($url, ['academic_year_id' => $this->year->id, 'assignments' => [
            ['subject_id' => $a->id, 'staff_id' => $first->id],
            ['subject_id' => $b->id, 'staff_id' => $first->id],
            ['subject_id' => $c->id, 'staff_id' => $first->id],
        ]])->assertOk();

        $this->as($this->admin)->putJson($url, ['academic_year_id' => $this->year->id, 'assignments' => [
            ['subject_id' => $a->id, 'staff_id' => $second->id],
            ['subject_id' => $b->id, 'staff_id' => null],
        ]])->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.staff_id', $second->id);

        $this->assertSame(1, SubjectAssignment::count());
    }

    public function test_bulk_errors_are_keyed_per_row_and_nothing_is_written(): void
    {
        $good = $this->subjectInCurriculum();
        $outside = Subject::factory()->create();
        $teacher = $this->teacher();
        $wrongShift = $this->teacher([], Shift::factory()->create());

        $this->as($this->admin)->putJson("/api/sections/{$this->section->id}/subject-teachers", [
            'academic_year_id' => $this->year->id,
            'assignments' => [
                ['subject_id' => $good->id, 'staff_id' => $teacher->id],
                ['subject_id' => $outside->id, 'staff_id' => $teacher->id],
                ['subject_id' => $good->id, 'staff_id' => $wrongShift->id],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['assignments.1.subject_id', 'assignments.2.staff_id', 'assignments.2.subject_id']);

        $this->assertDatabaseCount('subject_assignments', 0);
    }

    public function test_bulk_validates_the_payload(): void
    {
        $url = "/api/sections/{$this->section->id}/subject-teachers";

        $this->as($this->admin)->putJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['academic_year_id', 'assignments']);
        $this->as($this->admin)->putJson($url, ['academic_year_id' => $this->year->id, 'assignments' => [['subject_id' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['assignments.0.staff_id']);
        $this->as($this->admin)->putJson('/api/sections/9999/subject-teachers', ['academic_year_id' => $this->year->id, 'assignments' => []])->assertNotFound();
        $this->as($this->admin)->putJson('/api/sections/1abc/subject-teachers', [])->assertNotFound();
    }

    // Guards

    public function test_deleting_a_staff_member_with_assignments_returns_409(): void
    {
        $assignment = SubjectAssignment::factory()->create([
            'class_id' => $this->class->id, 'section_id' => $this->section->id, 'academic_year_id' => $this->year->id,
        ]);

        $this->as($this->admin)->deleteJson("/api/staff/{$assignment->staff_id}")->assertStatus(409);
        $this->assertNotSoftDeleted('staff', ['id' => $assignment->staff_id]);
    }

    // canEnterMarks, against the real database

    public function test_can_enter_marks(): void
    {
        $subject = $this->subjectInCurriculum();
        $assigned = $this->teacher();
        $assignedUser = $this->userWithRole('teacher');
        $assigned->update(['user_id' => $assignedUser->id]);
        $this->as($this->admin)->postJson('/api/subject-assignments', $this->payload($subject, $assigned))->assertCreated();

        $otherUser = $this->userWithRole('teacher');
        $this->teacher()->update(['user_id' => $otherUser->id]);
        $unlinked = $this->userWithRole('teacher');
        $service = app(SubjectAssignmentService::class);
        $otherYear = AcademicYear::factory()->create();

        $this->assertTrue($service->canEnterMarks($this->admin, $this->section, $subject, $this->year));
        $this->assertTrue($service->canEnterMarks($assignedUser, $this->section, $subject, $this->year));
        $this->assertFalse($service->canEnterMarks($otherUser, $this->section, $subject, $this->year));
        $this->assertFalse($service->canEnterMarks($unlinked, $this->section, $subject, $this->year));
        $this->assertFalse($service->canEnterMarks($assignedUser, $this->section, $subject, $otherYear));
        $this->assertFalse($service->canEnterMarks($assignedUser, $this->section, Subject::factory()->create(), $this->year));
    }
}
