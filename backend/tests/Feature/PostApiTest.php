<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostApiTest extends TestCase
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
        $this->getJson('/api/posts')->assertUnauthorized();
    }

    public function test_teacher_is_forbidden_from_every_action(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $post = Post::factory()->create();

        $this->actingAs($teacher, 'sanctum')->getJson('/api/posts')->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->getJson("/api/posts/{$post->id}")->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->postJson('/api/posts', [])->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->putJson("/api/posts/{$post->id}", [])->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->deleteJson("/api/posts/{$post->id}")->assertForbidden();
    }

    public function test_index_returns_paginated_resources(): void
    {
        Post::factory()->count(3)->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/posts')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'type', 'title', 'slug', 'excerpt', 'cover_image_url', 'is_published', 'published_at', 'updated_at', 'web_url']],
                'links',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonMissingPath('data.0.body');
    }

    public function test_index_filters_by_type_and_search(): void
    {
        Post::factory()->create(['title' => 'Science Fair', 'type' => Post::TYPE_NEWS]);
        Post::factory()->event()->create(['title' => 'Open Day']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/posts?type=event')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Open Day');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/posts?search=Science')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Science Fair');
    }

    public function test_store_creates_post_and_sets_author(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/posts', [
                'type' => 'news',
                'title' => 'Science Week',
                'body' => '<p>Fun facts about science.</p>',
                'is_published' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Science Week')
            ->assertJsonPath('data.slug', 'science-week')
            ->assertJsonPath('data.body', '<p>Fun facts about science.</p>')
            ->assertJsonPath('message', 'Post created successfully');

        $this->assertDatabaseHas('posts', [
            'slug' => 'science-week',
            'author_id' => $this->admin->id,
        ]);

        $response->assertJsonMissingPath('data.author_id');
    }

    public function test_store_validates_required_fields_and_type(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/posts', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'title', 'body']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/posts', ['type' => 'invalid', 'title' => 'X', 'body' => '<p>x</p>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_event_requires_starts_at(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/posts', ['type' => 'event', 'title' => 'Science Week', 'body' => '<p>Fun</p>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event_starts_at');
    }

    public function test_store_rejects_a_body_that_is_only_an_empty_paragraph(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/posts', ['type' => 'news', 'title' => 'Empty', 'body' => '<p></p>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_store_sanitizes_the_body(): void
    {
        $body = '<script>alert(1)</script>'.
            '<p onerror="alert(1)">Hello</p>'.
            '<a href="javascript:alert(1)">bad link</a>'.
            '<h1>Not allowed</h1>'.
            '<img src="/storage/posts/a.jpg" alt="A">'.
            '<iframe src="https://evil.com/x"></iframe>'.
            '<iframe src="https://www.youtube-nocookie.com/embed/abc123"></iframe>'.
            '<iframe src="https://player.vimeo.com/video/456"></iframe>';

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/posts', ['type' => 'news', 'title' => 'Sanitized', 'body' => $body])
            ->assertCreated();

        $stored = $response->json('data.body');

        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('onerror', $stored);
        $this->assertStringNotContainsString('javascript:', $stored);
        $this->assertStringNotContainsString('<h1', $stored);
        $this->assertStringNotContainsString('evil.com', $stored);
        $this->assertStringContainsString('<img', $stored);
        $this->assertStringContainsString('https://www.youtube-nocookie.com/embed/abc123', $stored);
        $this->assertStringContainsString('https://player.vimeo.com/video/456', $stored);
    }

    public function test_show_returns_full_body(): void
    {
        $post = Post::factory()->create(['body' => '<p>Full body</p>']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/posts/{$post->id}")
            ->assertOk()
            ->assertJsonPath('data.body', '<p>Full body</p>')
            ->assertJsonPath('data.body_html', '<p>Full body</p>');
    }

    public function test_update_sanitizes_the_body(): void
    {
        $post = Post::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/posts/{$post->id}", ['body' => '<script>alert(1)</script><p>Updated</p>'])
            ->assertOk()
            ->assertJsonPath('data.body', '<p>Updated</p>')
            ->assertJsonPath('message', 'Post updated successfully');
    }

    public function test_update_rejects_an_empty_body(): void
    {
        $post = Post::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/posts/{$post->id}", ['body' => '<p></p>'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    public function test_destroy_returns_no_content(): void
    {
        $post = Post::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/posts/{$post->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('posts', ['id' => $post->id]);
    }

    public function test_unknown_post_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/posts/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Record not found.']);
    }

    public function test_non_numeric_id_does_not_resolve_a_post(): void
    {
        $post = Post::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/posts/{$post->id}abc")
            ->assertNotFound();
    }
}
