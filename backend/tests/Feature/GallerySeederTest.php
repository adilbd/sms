<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryItem;
use Database\Seeders\GallerySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GallerySeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_published_galleries_with_images_and_a_video(): void
    {
        $this->seed(GallerySeeder::class);

        $this->assertTrue(Gallery::published()->count() >= 3);
        $this->assertTrue(GalleryItem::where('type', GalleryItem::TYPE_IMAGE)->exists());
        $this->assertTrue(GalleryItem::where('type', GalleryItem::TYPE_VIDEO)->whereNotNull('youtube_id')->exists());
    }

    public function test_running_the_seeder_twice_does_not_duplicate_galleries_or_items(): void
    {
        $this->seed(GallerySeeder::class);
        $galleryCount = Gallery::count();
        $itemCount = GalleryItem::count();
        $mediaCount = \App\Models\Media::count();

        $this->seed(GallerySeeder::class);

        $this->assertSame($galleryCount, Gallery::count());
        $this->assertSame($itemCount, GalleryItem::count());
        $this->assertSame($mediaCount, \App\Models\Media::count());
    }
}
