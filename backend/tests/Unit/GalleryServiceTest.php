<?php

namespace Tests\Unit;

use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Repositories\Contracts\GalleryRepositoryInterface;
use App\Services\GalleryService;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface — no database.
 */
class GalleryServiceTest extends TestCase
{
    public function test_create_computes_the_youtube_id_for_video_items_before_syncing(): void
    {
        $gallery = $this->galleryWithId(1);

        $this->mock(GalleryRepositoryInterface::class, function (MockInterface $mock) use ($gallery) {
            $mock->shouldReceive('create')
                ->once()
                ->withArgs(fn (array $data) => ! array_key_exists('items', $data) && $data['title'] === 'Sports Day')
                ->andReturn($gallery);

            $mock->shouldReceive('syncItems')
                ->once()
                ->withArgs(fn ($g, array $items) => $g === $gallery
                    && $items[0]['type'] === GalleryItem::TYPE_VIDEO
                    && $items[0]['youtube_id'] === 'dQw4w9WgXcQ')
                ->andReturn($gallery);
        });

        app(GalleryService::class)->create([
            'title' => 'Sports Day',
            'items' => [
                ['type' => GalleryItem::TYPE_VIDEO, 'youtube_url' => 'https://youtu.be/dQw4w9WgXcQ'],
            ],
        ]);
    }

    public function test_create_leaves_image_items_untouched(): void
    {
        $gallery = $this->galleryWithId(1);

        $this->mock(GalleryRepositoryInterface::class, function (MockInterface $mock) use ($gallery) {
            $mock->shouldReceive('create')->once()->andReturn($gallery);
            $mock->shouldReceive('syncItems')
                ->once()
                ->withArgs(fn ($g, array $items) => $items[0]['type'] === GalleryItem::TYPE_IMAGE
                    && $items[0]['media_id'] === 5
                    && ! array_key_exists('youtube_id', $items[0]))
                ->andReturn($gallery);
        });

        app(GalleryService::class)->create([
            'title' => 'Campus',
            'items' => [
                ['type' => GalleryItem::TYPE_IMAGE, 'media_id' => 5],
            ],
        ]);
    }

    public function test_update_with_an_items_key_syncs_even_an_empty_list(): void
    {
        $gallery = $this->galleryWithId(1);

        $this->mock(GalleryRepositoryInterface::class, function (MockInterface $mock) use ($gallery) {
            $mock->shouldReceive('update')->once()->andReturn($gallery);
            $mock->shouldReceive('syncItems')
                ->once()
                ->withArgs(fn ($g, array $items) => $items === [])
                ->andReturn($gallery);
        });

        app(GalleryService::class)->update($gallery, ['items' => []]);
    }

    public function test_delete_delegates_to_the_repository(): void
    {
        $gallery = $this->galleryWithId(1);

        $this->mock(GalleryRepositoryInterface::class, function (MockInterface $mock) use ($gallery) {
            $mock->shouldReceive('delete')->once()->with($gallery);
        });

        app(GalleryService::class)->delete($gallery);
    }

    private function galleryWithId(int $id): Gallery
    {
        $gallery = new Gallery(['title' => 'Sports Day']);
        $gallery->id = $id;
        $gallery->exists = true;

        return $gallery;
    }
}
