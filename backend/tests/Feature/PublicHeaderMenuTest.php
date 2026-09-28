<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Page;
use Database\Seeders\MenuSeeder;
use Database\Seeders\PageSeeder;
use Database\Seeders\PublicContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicHeaderMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeded_bangla_labels_and_a_nested_child_link_render_on_home(): void
    {
        $this->seed(PublicContentSeeder::class);
        $this->seed(PageSeeder::class);
        $this->seed(MenuSeeder::class);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('আমাদের তথ্য', $html);
        $this->assertStringContainsString('প্রতিষ্ঠান সম্পর্কে', $html);

        $history = Page::where('slug', 'history')->firstOrFail();
        $this->assertStringContainsString('href="'.$history->url().'"', $html);
    }

    public function test_inactive_item_and_unpublished_page_item_are_hidden(): void
    {
        MenuItem::factory()->create(['label' => 'নিষ্ক্রিয় আইটেম', 'is_active' => false]);

        $draftPage = Page::factory()->draft()->create(['slug' => 'draft-handbook']);
        MenuItem::factory()->page($draftPage->id)->create(['label' => 'অপ্রকাশিত পাতা']);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('নিষ্ক্রিয় আইটেম', $html);
        $this->assertStringNotContainsString('অপ্রকাশিত পাতা', $html);
    }

    public function test_fallback_links_render_when_the_menu_is_empty(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('>Home<', $html);
        $this->assertStringContainsString('>Admissions<', $html);
    }

    public function test_a_future_dated_published_page_appears_once_its_time_passes_without_any_save(): void
    {
        $page = Page::factory()->create([
            'is_published' => true,
            'published_at' => now()->addMinutes(30),
            'title' => 'ভবিষ্যতে প্রকাশিতব্য পাতা',
        ]);

        MenuItem::factory()->page($page->id)->create(['label' => 'ভবিষ্যতের পাতার লিংক']);

        // Not yet visible: this request also populates the header cache, whose TTL
        // must be short enough to expire once the page's published_at arrives.
        $this->get('/')->assertOk()->assertDontSee('ভবিষ্যতের পাতার লিংক');

        $this->travelTo(now()->addMinutes(31));

        // No save happened on either the page or the menu item in between, so this
        // only passes if the cache entry itself expired on time.
        $this->get('/')->assertOk()->assertSee('ভবিষ্যতের পাতার লিংক');
    }

    public function test_every_public_page_still_has_exactly_one_h1_with_the_menu_seeded(): void
    {
        $this->seed(PublicContentSeeder::class);
        $this->seed(PageSeeder::class);
        $this->seed(MenuSeeder::class);

        foreach (['/', '/about', '/admissions', '/contact', '/news', '/events'] as $uri) {
            $html = $this->get($uri)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, '<h1'), "Expected exactly one <h1> on {$uri}");
        }
    }
}
