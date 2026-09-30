<?php

namespace Tests\Feature;

use App\Models\Classes;
use App\Models\Section;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassApiTest extends TestCase
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
        $this->getJson('/api/classes')->assertUnauthorized();
    }

    public function test_index_is_ordered_by_number_and_has_level(): void
    {
        Classes::factory()->create(['number' => 9, 'name' => 'Class 9']);
        Classes::factory()->create(['number' => 1, 'name' => 'Class 1']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/classes')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'number', 'name', 'name_bn', 'code', 'level', 'has_groups', 'is_active']],
                'links',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.number', 1)
            ->assertJsonPath('data.1.number', 9)
            ->assertJsonPath('data.0.level', 'primary')
            ->assertJsonPath('data.1.level', 'secondary')
            ->assertJsonPath('data.1.has_groups', true);
    }

    public function test_index_filters_by_level(): void
    {
        Classes::factory()->create(['number' => 3]);
        Classes::factory()->create(['number' => 11]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/classes?level=higher_secondary')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 11);
    }

    public function test_store_creates_class(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/classes', ['number' => 6, 'name' => 'Class 6', 'code' => 'c06'])
            ->assertCreated()
            ->assertJsonPath('data.number', 6)
            ->assertJsonPath('data.code', 'C06')
            ->assertJsonPath('data.level', 'junior_secondary')
            ->assertJsonPath('data.has_groups', false)
            ->assertJsonPath('message', 'Class created successfully');

        $this->assertDatabaseHas('classes', ['code' => 'C06']);
    }

    public function test_store_validates_number_range(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/classes', ['number' => 0, 'name' => 'X', 'code' => 'X0'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('number');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/classes', ['number' => 13, 'name' => 'X', 'code' => 'X13'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('number');
    }

    public function test_store_rejects_duplicate_number(): void
    {
        Classes::factory()->create(['number' => 4]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/classes', ['number' => 4, 'name' => 'Class 4 Again', 'code' => 'C04B'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('number');
    }

    public function test_store_rejects_duplicate_code_case_insensitively(): void
    {
        Classes::factory()->create(['number' => 2, 'code' => 'C02']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/classes', ['number' => 7, 'name' => 'Class 7', 'code' => 'c02'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_show_includes_sections(): void
    {
        $class = Classes::factory()->create();
        Section::factory()->create(['class_id' => $class->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/classes/{$class->id}")
            ->assertOk()
            ->assertJsonPath('data.sections_count', 1)
            ->assertJsonCount(1, 'data.sections');
    }

    public function test_update_class(): void
    {
        $class = Classes::factory()->create(['name' => 'Old Name']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/classes/{$class->id}", ['name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.name', 'New Name')
            ->assertJsonPath('message', 'Class updated successfully');
    }

    public function test_destroy_returns_no_content(): void
    {
        $class = Classes::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/classes/{$class->id}")
            ->assertNoContent();

        $this->assertSoftDeleted($class);
    }

    public function test_destroy_returns_409_when_class_has_sections(): void
    {
        $class = Classes::factory()->create();
        Section::factory()->create(['class_id' => $class->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/classes/{$class->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Class has sections and cannot be deleted.');

        $this->assertNotSoftDeleted($class);
    }

    public function test_unknown_class_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/classes/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Record not found.']);
    }

    public function test_non_numeric_id_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/classes/1abc')
            ->assertNotFound();
    }

    public function test_invalid_list_filters_are_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/classes?search[]=x&level=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['search', 'level']);
    }

    public function test_teacher_cannot_write_classes(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $class = Classes::factory()->create();

        $this->actingAs($teacher, 'sanctum')->postJson('/api/classes', ['number' => 5, 'name' => 'X', 'code' => 'X5'])->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->putJson("/api/classes/{$class->id}", ['name' => 'Y'])->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->deleteJson("/api/classes/{$class->id}")->assertForbidden();
    }

    public function test_errors_are_json_even_without_accept_header(): void
    {
        $this->get('/api/classes')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }
}
