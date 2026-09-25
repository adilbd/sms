<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Database\Seeders\PublicContentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PublicContentSeeder::class);
    }

    public function test_news_list_matches_website_and_hides_drafts(): void
    {
        $response = $this->getJson('/api/public/news')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'type', 'title', 'slug', 'excerpt', 'cover_image_url', 'published_at', 'web_url']],
                'links', 'meta' => ['current_page', 'last_page', 'total'],
            ])
            ->assertJsonMissingPath('data.0.body');

        $slugs = collect($response->json('data'))->pluck('slug');
        $this->assertNotContains('draft-upcoming-announcement', $slugs);
        $this->assertEquals(Post::published()->ofType('news')->count(), $response->json('meta.total'));

        // Same first item as the website list.
        $this->get('/news')->assertSee($response->json('data.0.title'));
    }

    public function test_news_detail_includes_body(): void
    {
        $post = Post::published()->ofType('news')->firstOrFail();

        $this->getJson("/api/public/news/{$post->slug}")
            ->assertOk()
            ->assertJsonPath('data.slug', $post->slug)
            ->assertJsonPath('data.web_url', url("/news/{$post->slug}"))
            ->assertJsonStructure(['data' => ['body', 'body_html']]);
    }

    public function test_events_endpoints(): void
    {
        $event = Post::published()->ofType('event')->firstOrFail();

        $this->getJson('/api/public/events')->assertOk()->assertJsonStructure(['data' => [['event_starts_at', 'location']]]);
        $this->getJson("/api/public/events/{$event->slug}")->assertOk()->assertJsonPath('data.type', 'event');
        $this->getJson('/api/public/events/missing')->assertNotFound();
    }

    public function test_school_info(): void
    {
        $this->getJson('/api/public/school')->assertOk()->assertJsonStructure(['data' => ['name', 'email', 'phone', 'address', 'web_url']]);
    }

    public function test_contact_via_api_without_csrf(): void
    {
        $this->postJson('/api/public/contact', ['name' => 'App User', 'email' => 'app@example.com', 'message' => 'Hi'])
            ->assertCreated();

        $this->assertDatabaseHas('contact_messages', ['email' => 'app@example.com', 'source' => 'mobile']);

        $this->postJson('/api/public/contact', [])->assertUnprocessable();
    }

    public function test_posts_admin_api_requires_admin_role(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::where('email', 'admin@sms.com')->firstOrFail();
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');

        $this->getJson('/api/posts')->assertUnauthorized();

        $this->actingAs($teacher, 'sanctum')->getJson('/api/posts')->assertForbidden();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/posts', ['type' => 'event', 'title' => 'Science Week', 'body' => 'Fun', 'is_published' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event_starts_at');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/posts', ['type' => 'news', 'title' => 'Science Week', 'body' => 'Fun', 'is_published' => true])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'science-week');

        $this->getJson('/api/public/news/science-week')->assertOk();
    }

    public function test_login_accepts_device_name_for_mobile(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->postJson('/api/login', ['email' => 'admin@sms.com', 'password' => 'password', 'device_name' => 'iPhone 17'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'iPhone 17']);
    }
}
