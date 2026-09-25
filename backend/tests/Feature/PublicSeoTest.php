<?php

namespace Tests\Feature;

use App\Models\Post;
use Database\Seeders\PublicContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PublicContentSeeder::class);
    }

    public static function publicPages(): array
    {
        return [
            'home' => ['/'],
            'about' => ['/about'],
            'admissions' => ['/admissions'],
            'contact' => ['/contact'],
            'news index' => ['/news'],
            'events index' => ['/events'],
        ];
    }

    /** @dataProvider publicPages */
    public function test_public_page_is_server_rendered_with_seo_tags(string $uri): void
    {
        $response = $this->get($uri)->assertOk();
        $html = $response->getContent();

        $this->assertSeoHead($html, url($uri === '/' ? '/' : $uri));
        $this->assertSame(1, substr_count($html, '<h1'), "Expected exactly one <h1> on {$uri}");
        $this->assertStringContainsString('index, follow', $html);
    }

    public function test_titles_and_descriptions_are_unique_across_pages(): void
    {
        $titles = [];
        $descriptions = [];

        foreach (self::publicPages() as [$uri]) {
            $html = $this->get($uri)->getContent();
            preg_match('/<title>(.*?)<\/title>/s', $html, $t);
            preg_match('/<meta name="description" content="(.*?)">/s', $html, $d);
            $titles[] = $t[1];
            $descriptions[] = $d[1];
        }

        $this->assertCount(count($titles), array_unique($titles));
        $this->assertCount(count($descriptions), array_unique($descriptions));
    }

    public function test_news_detail_has_article_seo_and_content(): void
    {
        $post = Post::published()->ofType(Post::TYPE_NEWS)->firstOrFail();

        $html = $this->get("/news/{$post->slug}")->assertOk()->getContent();

        $this->assertSeoHead($html, url("/news/{$post->slug}"));
        $this->assertStringContainsString('<meta property="og:type" content="article">', $html);
        $this->assertStringContainsString('article:published_time', $html);
        $this->assertStringContainsString(e($post->title), $html);
        $types = $this->jsonLdTypes($html);
        $this->assertContains('NewsArticle', $types);
        $this->assertContains('BreadcrumbList', $types);
    }

    public function test_event_detail_has_event_schema(): void
    {
        $post = Post::published()->ofType(Post::TYPE_EVENT)->firstOrFail();

        $html = $this->get("/events/{$post->slug}")->assertOk()->getContent();

        $this->assertSeoHead($html, url("/events/{$post->slug}"));
        $event = collect($this->jsonLd($html))->firstWhere('@type', 'Event');
        $this->assertNotNull($event);
        $this->assertSame($post->event_starts_at->toIso8601String(), $event['startDate']);
        $this->assertArrayHasKey('location', $event);
    }

    public function test_home_has_organization_schema(): void
    {
        $types = $this->jsonLdTypes($this->get('/')->getContent());

        $this->assertContains('EducationalOrganization', $types);
        $this->assertContains('WebSite', $types);
    }

    public function test_unknown_and_draft_posts_return_404_with_noindex(): void
    {
        $this->get('/news/does-not-exist')->assertNotFound()->assertSee('noindex, nofollow', false);
        $this->get('/news/draft-upcoming-announcement')->assertNotFound();
        $this->get('/does-not-exist')->assertNotFound()->assertSee('noindex, nofollow', false);
        // A news slug must not resolve under /events and vice versa.
        $news = Post::published()->ofType(Post::TYPE_NEWS)->firstOrFail();
        $this->get("/events/{$news->slug}")->assertNotFound();
    }

    public function test_canonical_ignores_tracking_query_strings(): void
    {
        $html = $this->get('/about?utm_source=newsletter')->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.url('/about').'">', $html);
    }

    public function test_sitemap_lists_static_pages_and_only_published_posts(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk();
        $this->assertStringStartsWith('application/xml', $response->headers->get('Content-Type'));

        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml, 'Sitemap is not valid XML');

        $locs = collect();
        foreach ($xml->url as $url) {
            $locs->push((string) $url->loc);
        }

        foreach (['/', '/about', '/admissions', '/contact', '/news', '/events'] as $uri) {
            $this->assertContains(url($uri), $locs);
        }

        foreach (Post::published()->get() as $post) {
            $this->assertContains($post->url(), $locs);
        }

        $this->assertNotContains(url('/news/draft-upcoming-announcement'), $locs);
        $this->assertFalse($locs->contains(fn ($l) => str_contains($l, '/admin')));
    }

    public function test_sitemap_refreshes_when_a_post_is_published(): void
    {
        $this->get('/sitemap.xml');

        $post = Post::create(['type' => 'news', 'title' => 'Brand New Story', 'body' => 'Hello', 'is_published' => true]);

        $this->get('/sitemap.xml')->assertSee($post->url(), false);
    }

    public function test_robots_txt_in_production_allows_site_and_blocks_admin(): void
    {
        $this->app['env'] = 'production';

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Allow: /', false)
            ->assertSee('Disallow: /admin', false)
            ->assertSee('Disallow: /api', false)
            ->assertSee('Sitemap: '.url('/sitemap.xml'), false);
    }

    public function test_robots_txt_blocks_everything_outside_production(): void
    {
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /', false)->assertDontSee('Allow: /', false);
    }

    public function test_admin_spa_is_noindex_and_catches_deep_links(): void
    {
        foreach (['/admin', '/admin/login', '/admin/students/5'] as $uri) {
            $this->get($uri)->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false)->assertSee('<div id="app">', false);
        }
    }

    public function test_contact_form_stores_message(): void
    {
        $this->post('/contact', ['name' => 'Jane', 'email' => 'jane@example.com', 'message' => 'Hello'])
            ->assertRedirect('/contact')
            ->assertSessionHas('status');

        $this->assertDatabaseHas('contact_messages', ['email' => 'jane@example.com', 'source' => 'web']);
    }

    public function test_contact_form_validates_and_ignores_honeypot(): void
    {
        $this->post('/contact', ['name' => '', 'email' => 'nope'])->assertSessionHasErrors(['name', 'email', 'message']);

        $this->post('/contact', ['name' => 'Bot', 'email' => 'bot@example.com', 'message' => 'spam', 'website' => 'http://spam'])
            ->assertRedirect('/contact');
        $this->assertDatabaseMissing('contact_messages', ['email' => 'bot@example.com']);
    }

    private function assertSeoHead(string $html, string $canonical): void
    {
        $this->assertMatchesRegularExpression('/<title>[^<]+<\/title>/', $html);
        $this->assertMatchesRegularExpression('/<meta name="description" content="[^"]{20,160}">/u', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.$canonical.'">', $html);
        foreach (['og:title', 'og:description', 'og:url', 'og:type', 'og:image', 'twitter:card'] as $tag) {
            $this->assertStringContainsString($tag, $html, "Missing {$tag}");
        }
        $this->assertNotEmpty($this->jsonLd($html), 'Missing JSON-LD');
    }

    private function jsonLd(string $html): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $m);

        return array_map(function ($json) {
            $decoded = json_decode($json, true);
            $this->assertIsArray($decoded, 'Invalid JSON-LD: '.$json);

            return $decoded;
        }, $m[1]);
    }

    private function jsonLdTypes(string $html): array
    {
        return array_column($this->jsonLd($html), '@type');
    }
}
