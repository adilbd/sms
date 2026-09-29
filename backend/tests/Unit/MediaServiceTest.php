<?php

namespace Tests\Unit;

use App\Models\Media;
use App\Repositories\Contracts\MediaRepositoryInterface;
use App\Services\MediaService;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface — no database.
 */
class MediaServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_upload_stores_the_file_and_records_its_dimensions(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 400, 300);

        $this->mock(MediaRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('create')
                ->once()
                ->withArgs(fn (array $data) => $data['disk'] === 'public'
                    && str_starts_with($data['path'], 'media/')
                    && $data['original_name'] === 'photo.jpg'
                    && $data['mime_type'] === 'image/jpeg'
                    && $data['width'] === 400
                    && $data['height'] === 300)
                ->andReturn(new Media);
        });

        app(MediaService::class)->upload($file);
    }

    public function test_upload_deletes_the_stored_file_when_the_row_fails_to_save(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 400, 300);

        $this->mock(MediaRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('create')->once()->andThrow(new RuntimeException('db down'));
        });

        try {
            app(MediaService::class)->upload($file);
            $this->fail('Expected the repository exception to propagate.');
        } catch (RuntimeException $e) {
            $this->assertSame('db down', $e->getMessage());
        }

        $this->assertEmpty(
            Storage::disk('public')->allFiles('media'),
            'Expected the uploaded file to be deleted after the row failed to save.'
        );
    }

    public function test_delete_is_refused_when_the_image_is_used_in_a_gallery_item(): void
    {
        $media = $this->mediaWithId(1);

        $this->mock(MediaRepositoryInterface::class, function (MockInterface $mock) use ($media) {
            $mock->shouldReceive('isUsedInGalleryItems')->once()->with($media)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        try {
            app(MediaService::class)->delete($media);
            $this->fail('Expected a 409 HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_delete_removes_the_row_and_the_file_when_unused(): void
    {
        Storage::disk('public')->put('media/a.jpg', 'contents');
        $media = $this->mediaWithId(1, 'media/a.jpg');

        $this->mock(MediaRepositoryInterface::class, function (MockInterface $mock) use ($media) {
            $mock->shouldReceive('isUsedInGalleryItems')->once()->with($media)->andReturn(false);
            $mock->shouldReceive('delete')->once()->with($media);
        });

        app(MediaService::class)->delete($media);

        Storage::disk('public')->assertMissing('media/a.jpg');
    }

    public function test_delete_reports_a_concurrent_link_as_a_conflict_instead_of_a_server_error(): void
    {
        Storage::disk('public')->put('media/a.jpg', 'contents');
        $media = $this->mediaWithId(1, 'media/a.jpg');

        // Real PDO drivers report a foreign key violation with a string SQLSTATE code
        // ('23000'), which PDOException::$code (protected) doesn't allow constructing
        // directly with a string — set it the same way the driver would.
        $previous = new PDOException('integrity constraint violation');
        (new \ReflectionProperty(PDOException::class, 'code'))->setValue($previous, '23000');
        $queryException = new QueryException('sqlite', 'delete from media where id = ?', [1], $previous);

        $this->mock(MediaRepositoryInterface::class, function (MockInterface $mock) use ($media, $queryException) {
            $mock->shouldReceive('isUsedInGalleryItems')->once()->with($media)->andReturn(false);
            $mock->shouldReceive('delete')->once()->with($media)->andThrow($queryException);
        });

        try {
            app(MediaService::class)->delete($media);
            $this->fail('Expected a 409 HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        // The delete never committed, so the file that backs the still-existing row
        // must be kept.
        Storage::disk('public')->assertExists('media/a.jpg');
    }

    private function mediaWithId(int $id, string $path = 'media/a.jpg'): Media
    {
        $media = new Media(['disk' => 'public', 'path' => $path]);
        $media->id = $id;
        $media->exists = true;

        return $media;
    }
}
