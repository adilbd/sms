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

    public function test_store_rejects_a_slug_with_non_ascii_letters(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/posts', [
                'type' => 'news',
                'title' => 'Arger',
                'slug' => 'ärger',
                'body' => '<p>Fun facts about science.</p>',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slug');
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

    public function test_update_rejects_turning_a_news_post_into_an_event_without_a_start_date(): void
    {
        $post = Post::factory()->create(['type' => Post::TYPE_NEWS]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/posts/{$post->id}", ['type' => 'event'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event_starts_at');
    }

    public function test_update_rejects_an_end_date_before_the_saved_start_date(): void
    {
        $post = Post::factory()->event()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/posts/{$post->id}", [
                'event_ends_at' => $post->event_starts_at->subDay()->toIso8601String(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event_ends_at');
    }

    public function test_update_accepts_a_valid_ends_only_update(): void
    {
        $post = Post::factory()->event()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/posts/{$post->id}", [
                'event_ends_at' => $post->event_starts_at->addHours(4)->toIso8601String(),
            ])
            ->assertOk();
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

    public function test_update_on_unknown_post_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/posts/999', ['title' => 'X'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Record not found.']);
    }

    public function test_destroy_on_unknown_post_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/posts/999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Record not found.']);
    }

    public function test_store_lowercases_the_slug(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/posts', [
                'type' => 'news',
                'title' => 'Slug Case',
                'body' => '<p>x</p>',
                'slug' => 'MyCustomSlug',
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'mycustomslug');
    }

    public function test_update_lowercases_the_slug(): void
    {
        $post = Post::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/posts/{$post->id}", ['slug' => 'UpperCaseSlug'])
            ->assertOk()
            ->assertJsonPath('data.slug', 'uppercaseslug');
    }

    /**
     * PostResource exposes the raw meta_title, meta_description, cover_image and
     * custom_excerpt columns (not just their computed fallbacks), so PostForm.vue can
     * read them back without wiping them on the next save.
     */
    public function test_meta_cover_and_excerpt_round_trip_through_show_and_update(): void
    {
        $post = Post::factory()->create([
            'excerpt' => 'A custom excerpt',
            'cover_image' => 'posts/2026/09/cover.jpg',
            'meta_title' => 'A meta title',
            'meta_description' => 'A meta description',
        ]);

        $show = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/posts/{$post->id}")
            ->assertOk()
            ->assertJsonPath('data.custom_excerpt', 'A custom excerpt')
            ->assertJsonPath('data.cover_image', 'posts/2026/09/cover.jpg')
            ->assertJsonPath('data.meta_title', 'A meta title')
            ->assertJsonPath('data.meta_description', 'A meta description');

        // Mirrors what PostForm.vue sends back: `excerpt` mapped from `custom_excerpt`,
        // the other three read and resent as-is.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/posts/{$post->id}", [
                'excerpt' => $show->json('data.custom_excerpt'),
                'cover_image' => $show->json('data.cover_image'),
                'meta_title' => $show->json('data.meta_title'),
                'meta_description' => $show->json('data.meta_description'),
            ])
            ->assertOk()
            ->assertJsonPath('data.custom_excerpt', 'A custom excerpt')
            ->assertJsonPath('data.cover_image', 'posts/2026/09/cover.jpg')
            ->assertJsonPath('data.meta_title', 'A meta title')
            ->assertJsonPath('data.meta_description', 'A meta description');
    }

    public function test_a_null_custom_excerpt_stays_null_after_update(): void
    {
        $post = Post::factory()->create(['excerpt' => null]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/posts/{$post->id}", ['excerpt' => null])
            ->assertOk()
            ->assertJsonPath('data.custom_excerpt', null);
    }
}
