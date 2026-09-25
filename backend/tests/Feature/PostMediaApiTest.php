<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PostMediaApiTest extends TestCase
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
        $this->postJson('/api/posts/media', [])->assertUnauthorized();
    }

    public function test_teacher_is_forbidden(): void
    {
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');

        $this->actingAs($teacher, 'sanctum')
            ->postJson('/api/posts/media', ['file' => UploadedFile::fake()->image('photo.jpg')])
            ->assertForbidden();
    }

    public function test_admin_can_upload_a_jpg(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 200, 200);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/posts/media', ['file' => $file])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['path', 'url']]);

        $path = $response->json('data.path');
        $url = $response->json('data.url');

        $this->assertStringStartsWith('posts/', $path);
        $this->assertStringContainsString('/storage/posts/', $url);
        $this->assertStringStartsWith('http', $url);
        Storage::disk('public')->assertExists($path);
    }

    public function test_missing_file_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/posts/media', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_pdf_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/posts/media', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_svg_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('icon.svg', 5, 'image/svg+xml');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/posts/media', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_file_over_5mb_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('big.jpg', 5121, 'image/jpeg');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/posts/media', ['file' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }
}
