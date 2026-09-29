<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GalleryApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@sms.com')->firstOrFail();
        Storage::fake('public');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/galleries')->assertUnauthorized();
    }

    public function test_teacher_student_and_parent_are_forbidden_from_every_action(): void
    {
        $gallery = Gallery::factory()->create();

        foreach (['teacher', 'student', 'parent'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user, 'sanctum')->getJson('/api/galleries')->assertForbidden();
            $this->actingAs($user, 'sanctum')->getJson("/api/galleries/{$gallery->id}")->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson('/api/galleries', [])->assertForbidden();
            $this->actingAs($user, 'sanctum')->putJson("/api/galleries/{$gallery->id}", [])->assertForbidden();
            $this->actingAs($user, 'sanctum')->deleteJson("/api/galleries/{$gallery->id}")->assertForbidden();
        }
    }

    public function test_index_returns_paginated_resources(): void
    {
        Gallery::factory()->count(3)->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/galleries')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'title', 'slug', 'description', 'cover_media_id', 'cover_url', 'item_count', 'is_published', 'published_at', 'sort_order', 'created_at', 'updated_at', 'web_url']],
                'links',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 3);
    }

    public function test_index_does_not_include_each_gallery_full_items_array(): void
    {
        $gallery = Gallery::factory()->create();
        GalleryItem::factory()->create(['gallery_id' => $gallery->id]);

        $response = $this->actingAs($this->admin, 'sanctum')->getJson('/api/galleries')->assertOk();

        $this->assertArrayNotHasKey('items', $response->json('data.0'));
    }

    public function test_show_includes_the_items_array(): void
    {
        $gallery = Gallery::factory()->create();
        GalleryItem::factory()->create(['gallery_id' => $gallery->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/galleries/{$gallery->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items');
    }

    public function test_store_creates_a_gallery_with_ordered_image_and_video_items(): void
    {
        $media1 = Media::factory()->create();
        $media2 = Media::factory()->create();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/galleries', [
                'title' => 'Annual Sports Day',
                'description' => 'Photos and a video from the day.',
                'is_published' => true,
                'items' => [
                    ['type' => 'image', 'media_id' => $media1->id, 'caption' => 'Opening march'],
                    ['type' => 'image', 'media_id' => $media2->id, 'caption' => 'Finish line'],
                    ['type' => 'video', 'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'caption' => 'Highlights'],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Annual Sports Day')
            ->assertJsonPath('data.slug', 'annual-sports-day')
            ->assertJsonPath('data.item_count', 3)
            ->assertJsonPath('message', 'Gallery created successfully');

        $items = $response->json('data.items');
        $this->assertCount(3, $items);
        $this->assertSame($media1->id, $items[0]['media_id']);
        $this->assertSame('Opening march', $items[0]['caption']);
        $this->assertSame($media2->id, $items[1]['media_id']);
        $this->assertSame('video', $items[2]['type']);
        $this->assertSame('dQw4w9WgXcQ', $items[2]['youtube_id']);
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', $items[2]['embed_url']);
    }

    public function test_update_reorders_and_removes_an_item(): void
    {
        $gallery = Gallery::factory()->create();
        $media1 = Media::factory()->create();
        $media2 = Media::factory()->create();
        $item1 = GalleryItem::factory()->create(['gallery_id' => $gallery->id, 'media_id' => $media1->id, 'sort_order' => 0]);
        $item2 = GalleryItem::factory()->create(['gallery_id' => $gallery->id, 'media_id' => $media2->id, 'sort_order' => 1]);
        $item3 = GalleryItem::factory()->create(['gallery_id' => $gallery->id, 'media_id' => $media1->id, 'sort_order' => 2]);

        // Reverse the first two items and drop the third.
        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/galleries/{$gallery->id}", [
                'items' => [
                    ['id' => $item2->id, 'type' => 'image', 'media_id' => $media2->id],
                    ['id' => $item1->id, 'type' => 'image', 'media_id' => $media1->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.item_count', 2);

        $items = $response->json('data.items');
        $this->assertSame($item2->id, $items[0]['id']);
        $this->assertSame($item1->id, $items[1]['id']);
        $this->assertDatabaseMissing('gallery_items', ['id' => $item3->id]);
        $this->assertDatabaseHas('gallery_items', ['id' => $item1->id, 'sort_order' => 1]);
        $this->assertDatabaseHas('gallery_items', ['id' => $item2->id, 'sort_order' => 0]);
    }

    public function test_update_with_an_item_id_from_another_gallery_creates_a_new_item_and_leaves_the_other_gallery_untouched(): void
    {
        $gallery = Gallery::factory()->create();
        $otherGallery = Gallery::factory()->create();
        $media = Media::factory()->create();
        $foreignItem = GalleryItem::factory()->create(['gallery_id' => $otherGallery->id, 'media_id' => $media->id]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/galleries/{$gallery->id}", [
                'items' => [
                    ['id' => $foreignItem->id, 'type' => 'image', 'media_id' => $media->id, 'caption' => 'Borrowed id'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.item_count', 1);

        $newItemId = $response->json('data.items.0.id');
        $this->assertNotSame($foreignItem->id, $newItemId);

        // The other gallery's own item is untouched: still there, unchanged, still theirs.
        $this->assertDatabaseHas('gallery_items', [
            'id' => $foreignItem->id,
            'gallery_id' => $otherGallery->id,
            'caption' => $foreignItem->caption,
        ]);
        $this->assertDatabaseHas('gallery_items', [
            'id' => $newItemId,
            'gallery_id' => $gallery->id,
            'caption' => 'Borrowed id',
        ]);
    }

    public function test_update_without_an_items_key_leaves_existing_items_untouched(): void
    {
        $gallery = Gallery::factory()->create(['title' => 'Old Title']);
        $media = Media::factory()->create();
        $item = GalleryItem::factory()->create(['gallery_id' => $gallery->id, 'media_id' => $media->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/galleries/{$gallery->id}", ['title' => 'New Title'])
            ->assertOk()
            ->assertJsonPath('data.title', 'New Title')
            ->assertJsonPath('data.item_count', 1);

        $this->assertDatabaseHas('gallery_items', ['id' => $item->id]);
    }

    public function test_store_validates_required_title(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/galleries', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('title');
    }

    public function test_store_rejects_an_image_item_without_media_id(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/galleries', [
                'title' => 'X',
                'items' => [['type' => 'image']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.media_id']);
    }

    public function test_store_rejects_an_image_item_with_a_nonexistent_media_id(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/galleries', [
                'title' => 'X',
                'items' => [['type' => 'image', 'media_id' => 999999]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.media_id']);
    }

    public function test_store_rejects_a_non_youtube_video_url(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/galleries', [
                'title' => 'X',
                'items' => [['type' => 'video', 'youtube_url' => 'https://vimeo.com/123']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.youtube_url']);
    }

    public function test_store_rejects_a_lookalike_youtube_host(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/galleries', [
                'title' => 'X',
                'items' => [['type' => 'video', 'youtube_url' => 'https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.youtube_url']);
    }

    public function test_store_rejects_a_video_item_that_also_sends_media_id(): void
    {
        $media = Media::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/galleries', [
                'title' => 'X',
                'items' => [[
                    'type' => 'video',
                    'media_id' => $media->id,
                    'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.media_id']);
    }

    public function test_store_rejects_an_image_item_that_also_sends_youtube_url(): void
    {
        $media = Media::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/galleries', [
                'title' => 'X',
                'items' => [[
                    'type' => 'image',
                    'media_id' => $media->id,
                    'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                ]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.youtube_url']);
    }

    public function test_store_rejects_sort_order_above_the_maximum(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/galleries', ['title' => 'X', 'sort_order' => 65536])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['sort_order']);
    }

    public function test_store_rejects_an_unknown_item_type(): void
    {
        $media = Media::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/galleries', [
                'title' => 'X',
                'items' => [['type' => 'audio', 'media_id' => $media->id]],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.type']);
    }

    public function test_store_generates_an_ascii_slug_from_a_bangla_title(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/galleries', ['title' => 'বার্ষিক ক্রীড়া প্রতিযোগিতা'])
            ->assertCreated();

        $slug = $response->json('data.slug');
        $this->assertNotEmpty($slug);
        $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $slug);
    }

    public function test_store_appends_a_suffix_when_the_slug_collides(): void
    {
        Gallery::factory()->create(['title' => 'Campus', 'slug' => 'campus']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/galleries', ['title' => 'Campus'])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'campus-2');
    }

    public function test_destroy_removes_the_gallery_and_its_items_but_keeps_the_media_row(): void
    {
        $gallery = Gallery::factory()->create();
        $media = Media::factory()->create();
        $item = GalleryItem::factory()->create(['gallery_id' => $gallery->id, 'media_id' => $media->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/galleries/{$gallery->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('galleries', ['id' => $gallery->id]);
        $this->assertDatabaseMissing('gallery_items', ['id' => $item->id]);
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_deleting_the_cover_media_clears_it_and_falls_back_to_the_next_cover(): void
    {
        $coverMedia = Media::factory()->create();
        $fallbackMedia = Media::factory()->create();
        $gallery = Gallery::factory()->create(['cover_media_id' => $coverMedia->id]);
        GalleryItem::factory()->create([
            'gallery_id' => $gallery->id,
            'media_id' => $fallbackMedia->id,
            'sort_order' => 0,
        ]);

        // Not referenced by any gallery_items row (only by cover_media_id, which is
        // nullOnDelete), so the delete is allowed.
        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/media/{$coverMedia->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('media', ['id' => $coverMedia->id]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/galleries/{$gallery->id}")
            ->assertOk()
            ->assertJsonPath('data.cover_media_id', null);

        $this->assertSame($fallbackMedia->url(), $response->json('data.cover_url'));
    }

    public function test_unknown_gallery_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/galleries/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Record not found.']);
    }

    public function test_non_numeric_id_does_not_resolve_a_gallery(): void
    {
        $gallery = Gallery::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/galleries/{$gallery->id}abc")
            ->assertNotFound();
    }
}
