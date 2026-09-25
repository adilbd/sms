<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageApiTest extends TestCase
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
        $this->getJson('/api/pages')->assertUnauthorized();
    }

    public function test_teacher_student_and_parent_are_forbidden_from_every_action(): void
    {
        $page = Page::factory()->create();

        foreach (['teacher', 'student', 'parent'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user, 'sanctum')->getJson('/api/pages')->assertForbidden();
            $this->actingAs($user, 'sanctum')->getJson("/api/pages/{$page->id}")->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson('/api/pages', [])->assertForbidden();
            $this->actingAs($user, 'sanctum')->putJson("/api/pages/{$page->id}", [])->assertForbidden();
            $this->actingAs($user, 'sanctum')->deleteJson("/api/pages/{$page->id}")->assertForbidden();
        }
    }

    public function test_index_returns_paginated_resources(): void
    {
        Page::factory()->count(3)->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/pages')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'title', 'slug', 'excerpt', 'meta_title', 'meta_description', 'is_published', 'published_at', 'created_at', 'updated_at', 'web_url']],
                'links',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonMissingPath('data.0.body');
    }

    public function test_index_filters_by_search_and_published_status(): void
    {
        Page::factory()->create(['title' => 'Admissions Policy']);
        Page::factory()->create(['title' => 'Facilities Overview']);
        Page::factory()->draft()->create(['title' => 'Draft Handbook']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/pages?search=Admissions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Admissions Policy');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/pages?is_published=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Draft Handbook');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/pages?is_published=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_rejects_an_array_search_filter(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/pages?search[]=x')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
    }

    public function test_store_creates_page_with_generated_slug_and_published_at(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pages', [
                'title' => 'About Us',
                'body' => '<p>Welcome to our school.</p>',
                'is_published' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'About Us')
            ->assertJsonPath('data.slug', 'about-us')
            ->assertJsonPath('data.body', '<p>Welcome to our school.</p>')
            ->assertJsonPath('message', 'Page created successfully');

        $this->assertNotNull($response->json('data.published_at'));
        $this->assertDatabaseHas('pages', ['slug' => 'about-us']);
    }

    public function test_store_appends_a_suffix_when_the_slug_collides(): void
    {
        Page::factory()->create(['title' => 'About Us', 'slug' => 'about-us']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pages', ['title' => 'About Us', 'body' => '<p>Second</p>'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'about-us-2');
    }

    public function test_store_generates_an_ascii_slug_from_a_non_ascii_title(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pages', ['title' => 'Ärger und Söhne', 'body' => '<p>x</p>'])
            ->assertCreated()
            ->assertJsonPath('data.slug', fn ($slug) => (bool) preg_match('/^[\x20-\x7E]+$/', $slug));
    }

    public function test_store_rejects_a_duplicate_explicit_slug(): void
    {
        Page::factory()->create(['slug' => 'taken-slug']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pages', ['title' => 'New Page', 'body' => '<p>x</p>', 'slug' => 'taken-slug'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    public function test_store_rejects_a_slug_with_non_ascii_letters(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pages', ['title' => 'Arger', 'slug' => 'ärger', 'body' => '<p>x</p>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
    }

    public function test_update_may_keep_its_own_slug(): void
    {
        $page = Page::factory()->create(['slug' => 'my-page']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/pages/{$page->id}", ['slug' => 'my-page'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'my-page');
    }

    public function test_store_validates_required_fields(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pages', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['title', 'body']);
    }

    public function test_store_rejects_a_title_over_255_characters(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pages', ['title' => str_repeat('a', 256), 'body' => '<p>x</p>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_store_rejects_a_meta_description_over_300_characters(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pages', [
                'title' => 'X',
                'body' => '<p>x</p>',
                'meta_description' => str_repeat('a', 301),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('meta_description');
    }

    public function test_store_rejects_a_non_boolean_is_published(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pages', ['title' => 'X', 'body' => '<p>x</p>', 'is_published' => 'maybe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('is_published');
    }

    public function test_store_rejects_a_body_that_is_only_an_empty_paragraph(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pages', ['title' => 'Empty', 'body' => '<p></p>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_store_sanitizes_the_body(): void
    {
        $body = '<script>alert(1)</script>'.
            '<p onerror="alert(1)">Hello</p>'.
            '<h1>Not allowed</h1>'.
            '<img src="/storage/posts/a.jpg" alt="A">'.
            '<iframe src="https://evil.com/x"></iframe>'.
            '<iframe src="https://www.youtube-nocookie.com/embed/abc123"></iframe>';

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pages', ['title' => 'Sanitized', 'body' => $body])
            ->assertCreated();

        $stored = $response->json('data.body');

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('<h1', $stored);
        $this->assertStringNotContainsString('evil.com', $stored);
        $this->assertStringContainsString('<img', $stored);
        $this->assertStringContainsString('https://www.youtube-nocookie.com/embed/abc123', $stored);
    }

    public function test_show_returns_full_body(): void
    {
        $page = Page::factory()->create(['body' => '<p>Full body</p>']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/pages/{$page->id}")
            ->assertOk()
            ->assertJsonPath('data.body', '<p>Full body</p>');
    }

    public function test_update_returns_200_and_sanitizes_the_body(): void
    {
        $page = Page::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/pages/{$page->id}", ['body' => '<script>alert(1)</script><p>Updated</p>'])
            ->assertOk()
            ->assertJsonPath('data.body', '<p>Updated</p>')
            ->assertJsonPath('message', 'Page updated successfully');
    }

    public function test_update_rejects_an_empty_body(): void
    {
        $page = Page::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/pages/{$page->id}", ['body' => '<p></p>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_destroy_returns_no_content_and_removes_the_row(): void
    {
        $page = Page::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/pages/{$page->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('pages', ['id' => $page->id]);
    }

    public function test_unknown_page_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/pages/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Record not found.']);
    }

    public function test_non_numeric_id_does_not_resolve_a_page(): void
    {
        $page = Page::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/pages/{$page->id}abc")
            ->assertNotFound();
    }

    public function test_update_on_unknown_page_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/pages/999999', ['title' => 'X'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Record not found.']);
    }

    public function test_destroy_on_unknown_page_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/pages/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Record not found.']);
    }

    public function test_errors_are_json_even_without_accept_header(): void
    {
        $response = $this->withHeaders(['Accept' => ''])
            ->post('/api/pages', [], ['Authorization' => 'Bearer invalid-token']);

        $response->assertStatus(401);
        $this->assertJson($response->getContent());
    }
}
