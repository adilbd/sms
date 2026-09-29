<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GalleryPublicTest extends TestCase
{
    use RefreshDatabase;

    public function test_gallery_index_shows_only_published_galleries(): void
    {
        $published = Gallery::factory()->create(['title' => 'Published Gallery']);
        Gallery::factory()->draft()->create(['title' => 'Draft Gallery']);

        $html = $this->get('/gallery')->assertOk()->getContent();

        $this->assertStringContainsString('Published Gallery', $html);
        $this->assertStringNotContainsString('Draft Gallery', $html);
    }

    public function test_gallery_index_out_of_range_page_is_404(): void
    {
        Gallery::factory()->create();

        $this->get('/gallery?page=99')->assertNotFound();
    }

    public function test_gallery_index_renders_a_placeholder_and_empty_state_with_no_galleries(): void
    {
        $html = $this->get('/gallery')->assertOk()->getContent();

        $this->assertStringContainsString('Nothing here yet', $html);
    }

    public function test_gallery_show_renders_images_and_a_youtube_nocookie_embed(): void
    {
        $gallery = Gallery::factory()->create(['title' => 'Annual Sports Day']);
        $media = Media::factory()->create();
        GalleryItem::factory()->create([
            'gallery_id' => $gallery->id,
            'type' => GalleryItem::TYPE_IMAGE,
            'media_id' => $media->id,
            'caption' => 'Opening march',
        ]);
        GalleryItem::factory()->video()->create([
            'gallery_id' => $gallery->id,
            'caption' => 'Highlights',
        ]);

        $html = $this->get("/gallery/{$gallery->slug}")->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
        $this->assertStringContainsString(e($gallery->title), $html);
        $this->assertStringContainsString($media->url(), $html);
        $this->assertStringContainsString('youtube-nocookie.com/embed/dQw4w9WgXcQ', $html);
    }

    public function test_gallery_show_with_no_items_renders_an_empty_state(): void
    {
        $gallery = Gallery::factory()->create();

        $html = $this->get("/gallery/{$gallery->slug}")->assertOk()->getContent();

        $this->assertStringContainsString('No photos or videos have been added', $html);
    }

    public function test_unknown_and_draft_galleries_return_404_with_noindex(): void
    {
        Gallery::factory()->draft()->create(['slug' => 'draft-gallery']);

        $this->get('/gallery/does-not-exist')->assertNotFound()->assertSee('noindex, nofollow', false);
        $this->get('/gallery/draft-gallery')->assertNotFound()->assertSee('noindex, nofollow', false);
    }

    public function test_sitemap_lists_the_gallery_index_and_published_galleries_but_not_drafts(): void
    {
        $published = Gallery::factory()->create();
        $draft = Gallery::factory()->draft()->create();

        $xml = simplexml_load_string($this->get('/sitemap.xml')->assertOk()->getContent());
        $locs = collect();
        foreach ($xml->url as $url) {
            $locs->push((string) $url->loc);
        }

        $this->assertContains(url('/gallery'), $locs);
        $this->assertContains($published->url(), $locs);
        $this->assertNotContains($draft->url(), $locs);
    }

    public function test_public_api_lists_only_published_galleries(): void
    {
        $published = Gallery::factory()->create(['title' => 'Published Gallery']);
        Gallery::factory()->draft()->create(['title' => 'Draft Gallery']);

        $this->getJson('/api/public/galleries')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'title', 'slug', 'cover_url', 'item_count']], 'meta' => ['total']])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Published Gallery');
    }

    public function test_public_api_show_includes_items(): void
    {
        $gallery = Gallery::factory()->create();
        $media = Media::factory()->create();
        GalleryItem::factory()->create(['gallery_id' => $gallery->id, 'media_id' => $media->id]);

        $this->getJson("/api/public/galleries/{$gallery->slug}")
            ->assertOk()
            ->assertJsonPath('data.slug', $gallery->slug)
            ->assertJsonCount(1, 'data.items');
    }

    public function test_public_api_show_returns_404_for_a_draft_gallery(): void
    {
        $gallery = Gallery::factory()->draft()->create();

        $this->getJson("/api/public/galleries/{$gallery->slug}")->assertNotFound();
    }
}
