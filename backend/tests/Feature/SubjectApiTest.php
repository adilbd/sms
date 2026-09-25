<?php

namespace Tests\Feature;

use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

    public function test_store_creates_subject(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/subjects', ['name' => 'Chemistry', 'code' => 'CHEM'])
            ->assertCreated()
            ->assertJsonPath('data.code', 'CHEM')
            ->assertJsonPath('message', 'Subject created successfully');

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

    public function test_unknown_subject_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/subjects/999')
            ->assertNotFound()
            ->assertJsonStructure(['message']);
    }
}
