<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SubjectApiTest extends TestCase
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
        $this->getJson('/api/subjects')->assertUnauthorized();
    }

    public function test_index_returns_paginated_resources_and_filters_by_search(): void
    {
        Subject::factory()->create(['name' => 'Mathematics', 'code' => 'MATH']);
        Subject::factory()->create(['name' => 'Physics', 'code' => 'PHY']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/subjects')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'name', 'code', 'type', 'total_marks', 'pass_marks', 'description', 'is_active', 'created_at', 'updated_at']],
                'links',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 2);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/subjects?search=MATH')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'MATH');
    }

    public function test_per_page_is_clamped(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/subjects?per_page=1000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 100);

        foreach (['0', '-5', 'abc'] as $value) {
            $this->actingAs($this->admin, 'sanctum')
                ->getJson("/api/subjects?per_page={$value}")
                ->assertOk()
                ->assertJsonPath('meta.per_page', 1);
        }
    }

    public function test_store_creates_subject(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/subjects', ['name' => 'Chemistry', 'code' => 'CHEM'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'CHEM')
            ->assertJsonPath('message', 'Subject created successfully')
            // Database defaults are returned, not null.
            ->assertJsonPath('data.type', 'theory')
            ->assertJsonPath('data.total_marks', 100)
            ->assertJsonPath('data.pass_marks', 40)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('subjects', ['code' => 'CHEM']);
    }

    public function test_store_validates_input(): void
    {
        Subject::factory()->create(['code' => 'CHEM']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/subjects', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'code']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/subjects', ['name' => 'Chemistry', 'code' => 'CHEM'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_explicit_null_for_non_nullable_fields_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/subjects', ['name' => 'Chemistry', 'code' => 'CHEM', 'type' => null, 'is_active' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'is_active']);
    }

    public function test_index_filters_by_is_active_combined_with_search(): void
    {
        Subject::factory()->create(['name' => 'Math A', 'code' => 'MATH1', 'is_active' => true]);
        Subject::factory()->create(['name' => 'Math B', 'code' => 'MATH2', 'is_active' => false]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/subjects?search=MATH&is_active=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'MATH1');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/subjects?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'MATH2');

        // An empty filter is ignored rather than treated as false.
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/subjects?is_active=')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_invalid_list_filters_are_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/subjects?search[]=x&is_active=maybe')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search', 'is_active']);
    }

    public function test_show_and_update(): void
    {
        $subject = Subject::factory()->create(['name' => 'Biology', 'code' => 'BIO']);
        Subject::factory()->create(['code' => 'TAKEN']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/subjects/{$subject->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Biology');

        // Keeping its own code is allowed; another subject's code is not.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/subjects/{$subject->id}", ['name' => 'Life Science', 'code' => 'BIO'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Life Science')
            ->assertJsonPath('message', 'Subject updated successfully');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/subjects/{$subject->id}", ['code' => 'TAKEN'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_destroy_returns_no_content(): void
    {
        $subject = Subject::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/subjects/{$subject->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($subject);
    }

    public function test_unknown_subject_returns_404_without_leaking_the_model(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/subjects/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Record not found.']);
    }

    public function test_non_numeric_id_does_not_resolve_a_subject(): void
    {
        $subject = Subject::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/subjects/{$subject->id}abc")
            ->assertNotFound();

        $this->assertNotSoftDeleted($subject);
    }

    public function test_errors_are_json_even_without_accept_header(): void
    {
        $this->get('/api/subjects')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/subjects', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'code']);
    }

    public function test_student_can_view_but_not_modify_subjects(): void
    {
        $student = User::factory()->create();
        $student->assignRole('student');
        $subject = Subject::factory()->create();

        $this->actingAs($student, 'sanctum')->getJson('/api/subjects')->assertOk();
        $this->actingAs($student, 'sanctum')->getJson("/api/subjects/{$subject->id}")->assertOk();

        $this->actingAs($student, 'sanctum')->postJson('/api/subjects', ['name' => 'X', 'code' => 'X1'])->assertForbidden();
        $this->actingAs($student, 'sanctum')->putJson("/api/subjects/{$subject->id}", ['name' => 'Y'])->assertForbidden();
        $this->actingAs($student, 'sanctum')->deleteJson("/api/subjects/{$subject->id}")->assertForbidden();

        $this->assertNotSoftDeleted($subject);
    }

    public function test_type_and_marks_are_validated(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/subjects', ['name' => 'Art', 'code' => 'ART', 'type' => 'lab', 'total_marks' => -1, 'pass_marks' => 5000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'total_marks', 'pass_marks']);
    }

    public function test_pass_marks_cannot_exceed_total_marks(): void
    {
        // Omitted total_marks falls back to the default of 100.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/subjects', ['name' => 'Art', 'code' => 'ART', 'pass_marks' => 150])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pass_marks');

        // A partial update is checked against the saved pass_marks (40).
        $subject = Subject::factory()->create(['total_marks' => 100, 'pass_marks' => 40]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/subjects/{$subject->id}", ['total_marks' => 30])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pass_marks');

        $this->assertSame(100, $subject->fresh()->total_marks);
    }

    public function test_unrelated_update_is_allowed_on_a_subject_with_inconsistent_marks(): void
    {
        // Rows saved before the marks rule existed may have pass_marks > total_marks.
        $subject = Subject::factory()->create(['total_marks' => 50, 'pass_marks' => 60]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/subjects/{$subject->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_codes_are_stored_uppercase_and_compared_case_insensitively(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/subjects', ['name' => 'Music', 'code' => ' mus '])
            ->assertCreated()
            ->assertJsonPath('data.code', 'MUS');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/subjects', ['name' => 'Music 2', 'code' => 'Mus'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_destroy_returns_409_when_subject_is_used_in_exam_schedules(): void
    {
        $subject = Subject::factory()->create();
        $classId = DB::table('classes')->insertGetId(['name' => 'Class 1', 'code' => 'C1']);
        $sectionId = DB::table('sections')->insertGetId(['class_id' => $classId, 'name' => 'A', 'code' => 'A']);
        $yearId = DB::table('academic_years')->insertGetId(['name' => '2026', 'code' => 'AY26', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $examId = DB::table('exams')->insertGetId(['name' => 'Midterm', 'code' => 'MID', 'academic_year_id' => $yearId, 'type' => 'term', 'start_date' => '2026-06-01', 'end_date' => '2026-06-10']);
        DB::table('exam_schedules')->insert([
            'exam_id' => $examId, 'class_id' => $classId, 'section_id' => $sectionId, 'subject_id' => $subject->id,
            'exam_date' => '2026-06-02', 'start_time' => '09:00', 'end_time' => '11:00',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/subjects/{$subject->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Subject is used in exam schedules and cannot be deleted.');

        $this->assertNotSoftDeleted($subject);
    }
}
