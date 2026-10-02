<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Post;
use App\Support\PostBody;
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
            'gallery index' => ['/gallery'],
            'results lookup' => ['/results'],
            'results archive' => ['/results/archive'],
        ];
    }

    public function test_admission_pages_have_one_h1_and_private_ones_are_noindex(): void
    {
        $this->travelTo('2026-10-15 06:00:00');
        $year = \App\Models\AcademicYear::factory()->create(['year' => 2026]);
        $class = \App\Models\Classes::factory()->create(['number' => 6]);
        $round = \App\Models\AdmissionRound::factory()->create(['academic_year_id' => $year->id]);
        \App\Models\AdmissionRoundClass::create(['round_id' => $round->id, 'class_id' => $class->id, 'seats' => 10]);

        foreach (['/admissions', "/admissions/apply/{$round->id}"] as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();
            $this->assertSeoHead($html, url($uri));
            $this->assertSame(1, substr_count($html, '<h1'), $uri);
            $this->assertStringContainsString('index, follow', $html);
        }

        $html = $this->get('/admissions/status')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('noindex, nofollow', $html);
    }

    public function test_sitemap_lists_admissions_and_only_open_apply_pages(): void
    {
        $this->travelTo('2026-10-15 06:00:00');
        $year = \App\Models\AcademicYear::factory()->create(['year' => 2026]);
        $open = \App\Models\AdmissionRound::factory()->create(['academic_year_id' => $year->id]);
        $closed = \App\Models\AdmissionRound::factory()->closed()->create(['academic_year_id' => $year->id]);
        $draft = \App\Models\AdmissionRound::factory()->unpublished()->create(['academic_year_id' => $year->id]);

        $xml = $this->get('/sitemap.xml')->assertOk()->getContent();

        $this->assertStringContainsString('<loc>'.route('admissions').'</loc>', $xml);
        $this->assertStringContainsString($open->url(), $xml);
        $this->assertStringNotContainsString($closed->url(), $xml);
        $this->assertStringNotContainsString($draft->url(), $xml);
        $this->assertStringNotContainsString('/admissions/status', $xml);
        $this->assertStringNotContainsString('/admissions/submitted', $xml);
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

    public function test_news_detail_renders_the_sanitized_html_body_unescaped(): void
    {
        $post = Post::create([
            'type' => Post::TYPE_NEWS,
            'title' => 'A Story With A Photo',
            'body' => PostBody::sanitize('<p>Great turnout.</p><img src="/storage/posts/photo.jpg" alt="Students">'),
            'is_published' => true,
        ]);

        $html = $this->get("/news/{$post->slug}")->assertOk()->getContent();

        $this->assertStringContainsString('<img src="/storage/posts/photo.jpg" alt="Students">', $html);
    }

    public function test_html_body_with_an_h1_still_yields_exactly_one_h1(): void
    {
        // Sanitizing strips <h1> from the body (see PostBodyTest), so the page keeps a
        // single <h1>: the post title in the page header.
        $post = Post::create([
            'type' => Post::TYPE_NEWS,
            'title' => 'Heading Collision',
            'body' => PostBody::sanitize('<h1>Sneaky heading</h1><p>Body text</p>'),
            'is_published' => true,
        ]);

        $html = $this->get("/news/{$post->slug}")->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_meta_description_from_an_html_body_has_no_tags(): void
    {
        $post = Post::create([
            'type' => Post::TYPE_NEWS,
            'title' => 'No Excerpt Provided',
            'body' => PostBody::sanitize('<p>Plain <strong>rich</strong> text summary for search engines and social cards.</p>'),
            'is_published' => true,
        ]);

        $html = $this->get("/news/{$post->slug}")->assertOk()->getContent();

        preg_match('/<meta name="description" content="([^"]*)">/', $html, $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringNotContainsString('<', $matches[1]);
        $this->assertStringContainsString('Plain rich text summary', $matches[1]);
    }

    public function test_page_detail_has_full_seo_and_content(): void
    {
        $page = Page::factory()->create([
            'title' => 'Admissions Policy',
            'body' => PostBody::sanitize('<p>Read our full admissions policy for details on how to apply.</p><img src="/storage/posts/policy.jpg" alt="Policy">'),
        ]);

        $html = $this->get("/pages/{$page->slug}")->assertOk()->getContent();

        $this->assertSeoHead($html, url("/pages/{$page->slug}"));
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString(e($page->title), $html);
        $this->assertStringContainsString('<img src="/storage/posts/policy.jpg" alt="Policy">', $html);
        $this->assertContains('WebPage', $this->jsonLdTypes($html));
    }

    public function test_page_body_with_an_h1_still_yields_exactly_one_h1(): void
    {
        $page = Page::factory()->create([
            'title' => 'Heading Collision',
            'body' => PostBody::sanitize('<h1>Sneaky heading</h1><p>Body text</p>'),
        ]);

        $html = $this->get("/pages/{$page->slug}")->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_page_meta_description_falls_back_to_plain_text(): void
    {
        $page = Page::factory()->create([
            'title' => 'No Meta Description',
            'meta_description' => null,
            'body' => PostBody::sanitize('<p>Plain <strong>rich</strong> text summary for search engines.</p>'),
        ]);

        $html = $this->get("/pages/{$page->slug}")->assertOk()->getContent();

        preg_match('/<meta name="description" content="([^"]*)">/', $html, $matches);
        $this->assertNotEmpty($matches);
        $this->assertStringNotContainsString('<', $matches[1]);
        $this->assertStringContainsString('Plain rich text summary', $matches[1]);
    }

    public function test_unknown_draft_and_future_pages_return_404_with_noindex(): void
    {
        Page::factory()->draft()->create(['slug' => 'draft-handbook']);
        Page::factory()->create(['slug' => 'future-page', 'is_published' => true, 'published_at' => now()->addWeek()]);

        $this->get('/pages/does-not-exist')->assertNotFound()->assertSee('noindex, nofollow', false);
        $this->get('/pages/draft-handbook')->assertNotFound()->assertSee('noindex, nofollow', false);
        $this->get('/pages/future-page')->assertNotFound()->assertSee('noindex, nofollow', false);
    }

    public function test_sitemap_lists_published_pages_and_not_drafts(): void
    {
        $published = Page::factory()->create(['slug' => 'facilities']);
        $draft = Page::factory()->draft()->create(['slug' => 'draft-handbook']);

        $xml = simplexml_load_string($this->get('/sitemap.xml')->assertOk()->getContent());
        $locs = collect();
        foreach ($xml->url as $url) {
            $locs->push((string) $url->loc);
        }

        $this->assertContains($published->url(), $locs);
        $this->assertNotContains($draft->url(), $locs);
    }

    public function test_sitemap_refreshes_when_a_page_is_published(): void
    {
        $this->get('/sitemap.xml');

        $page = Page::create(['title' => 'Brand New Page', 'body' => '<p>Hello</p>', 'is_published' => true]);

        $this->get('/sitemap.xml')->assertSee($page->url(), false);
    }

    public function test_sitemap_refreshes_when_a_page_is_deleted(): void
    {
        $page = Page::factory()->create(['slug' => 'to-be-deleted']);

        $this->get('/sitemap.xml')->assertSee($page->url(), false);

        $page->delete();

        $this->get('/sitemap.xml')->assertDontSee($page->url(), false);
    }

    public function test_a_result_marksheet_is_noindex_with_one_h1_and_no_store(): void
    {
        $result = \App\Models\ExamResult::factory()->create([
            'exam_id' => \App\Models\Exam::factory()->create(['status' => \App\Models\Exam::STATUS_PUBLISHED, 'published_at' => now()]),
        ]);
        $student = $result->student;

        $response = $this->post('/results', [
            'exam_id' => $result->exam_id,
            'student_id' => $student->student_id,
            'date_of_birth' => $student->date_of_birth->toDateString(),
        ])->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_the_portal_login_has_a_full_head_one_h1_and_is_noindex_and_unlisted(): void
    {
        $response = $this->get('/portal/login')->assertOk();
        $html = $response->getContent();

        $this->assertMatchesRegularExpression('/<title>[^<]+<\/title>/', $html);
        $this->assertMatchesRegularExpression('/<meta name="description" content="[^"]{20,160}">/u', $html);
        foreach (['og:title', 'og:description', 'og:url', 'og:type', 'og:image', 'twitter:card'] as $tag) {
            $this->assertStringContainsString($tag, $html, "Missing {$tag}");
        }
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $html);
        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('X-Robots-Tag', 'noindex');

        $this->get('/sitemap.xml')->assertDontSee('/portal', false);
    }

    public function test_home_has_organization_schema(): void
    {
        $blocks = $this->jsonLd($this->get('/')->getContent());
        $types = array_column($blocks, '@type');

        $this->assertContains('EducationalOrganization', $types);
        $this->assertContains('WebSite', $types);

        // An empty institute settings table still yields the legalName from
        // config('seo.organization.legal_name'), same as before that module existed.
        $organization = $blocks[array_search('EducationalOrganization', $types, true)];
        $this->assertSame(config('seo.organization.legal_name'), $organization['legalName']);
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

        foreach (['/', '/about', '/admissions', '/contact', '/news', '/events', '/results', '/results/archive'] as $uri) {
            $this->assertContains(url($uri), $locs);
        }

        foreach (Post::published()->get() as $post) {
            $this->assertContains($post->url(), $locs);
        }

        $this->assertNotContains(url('/news/draft-upcoming-announcement'), $locs);
        // Loose str_contains('/admin') would also match the public '/administration/...'
        // staff pages, so check for the admin SPA path specifically.
        $this->assertFalse($locs->contains(fn ($l) => str_contains($l, '/admin/') || str_ends_with($l, '/admin')));
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
