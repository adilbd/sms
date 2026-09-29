<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@sms.com')->firstOrFail();

        // Storage::fake() drops the disk's "url" config unless it's passed back in, so
        // preserve it here to test the same absolute-URL behavior as the real public disk.
        Storage::fake('public', ['url' => config('filesystems.disks.public.url')]);
    }

    public function test_guest_is_unauthenticated(): void
    {
        $this->getJson('/api/media')->assertUnauthorized();
        $this->postJson('/api/media', [])->assertUnauthorized();
    }

    public function test_teacher_student_and_parent_are_forbidden_from_every_action(): void
    {
        $media = Media::factory()->create();

        foreach (['teacher', 'student', 'parent'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user, 'sanctum')->getJson('/api/media')->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson('/api/media', [])->assertForbidden();
            $this->actingAs($user, 'sanctum')->putJson("/api/media/{$media->id}", [])->assertForbidden();
            $this->actingAs($user, 'sanctum')->deleteJson("/api/media/{$media->id}")->assertForbidden();
        }
    }

    public function test_index_returns_paginated_resources(): void
    {
        Media::factory()->count(3)->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/media')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'disk', 'path', 'url', 'original_name', 'mime_type', 'size', 'width', 'height', 'alt', 'created_at', 'updated_at']],
                'links',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 3);
    }

    public function test_index_filters_by_search(): void
    {
        Media::factory()->create(['original_name' => 'sports-day.jpg']);
        Media::factory()->create(['original_name' => 'campus.jpg', 'alt' => 'Main building']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/media?search=sports')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.original_name', 'sports-day.jpg');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/media?search=Main+building')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.original_name', 'campus.jpg');
    }

    public function test_admin_can_upload_a_png(): void
    {
        $file = UploadedFile::fake()->image('photo.png', 200, 150);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/media', ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.original_name', 'photo.png')
            ->assertJsonPath('data.width', 200)
            ->assertJsonPath('data.height', 150)
            ->assertJsonPath('message', 'Image uploaded successfully');

        $path = $response->json('data.path');
        $url = $response->json('data.url');

        $this->assertStringStartsWith('media/', $path);
        $this->assertStringStartsWith('http', $url);
        Storage::disk('public')->assertExists($path);
        $this->assertDatabaseHas('media', ['path' => $path, 'original_name' => 'photo.png']);
    }

    public function test_pdf_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/media', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_file_over_5mb_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('big.jpg', 5121, 'image/jpeg');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/media', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_update_edits_alt_text(): void
    {
        $media = Media::factory()->create(['alt' => null]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/media/{$media->id}", ['alt' => 'Students at the sports day'])
            ->assertOk()
            ->assertJsonPath('data.alt', 'Students at the sports day')
            ->assertJsonPath('message', 'Image updated successfully');

        $this->assertDatabaseHas('media', ['id' => $media->id, 'alt' => 'Students at the sports day']);
    }

    public function test_update_rejects_an_alt_text_over_255_characters(): void
    {
        $media = Media::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/media/{$media->id}", ['alt' => str_repeat('a', 256)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('alt');
    }

    public function test_destroy_removes_an_unused_image_and_its_file(): void
    {
        $media = Media::factory()->create(['path' => 'media/unused.jpg']);
        Storage::disk('public')->put('media/unused.jpg', 'fake-contents');

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/media/{$media->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        Storage::disk('public')->assertMissing('media/unused.jpg');
    }

    public function test_destroy_is_refused_for_an_image_used_in_a_gallery_item_and_keeps_the_file(): void
    {
        $media = Media::factory()->create(['path' => 'media/used.jpg']);
        Storage::disk('public')->put('media/used.jpg', 'fake-contents');
        $gallery = Gallery::factory()->create();
        GalleryItem::factory()->create(['gallery_id' => $gallery->id, 'media_id' => $media->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/media/{$media->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('media', ['id' => $media->id]);
        Storage::disk('public')->assertExists('media/used.jpg');
    }

    public function test_unknown_media_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/media/999999', ['alt' => 'x'])
            ->assertNotFound()
            ->assertExactJson(['message' => 'Record not found.']);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson('/api/media/999999')
            ->assertNotFound();
    }

    public function test_non_numeric_id_does_not_resolve_a_media_row(): void
    {
        $media = Media::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/media/{$media->id}abc")
            ->assertNotFound();
    }
}
