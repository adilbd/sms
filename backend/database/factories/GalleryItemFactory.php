<?php

namespace Database\Factories;

use App\Models\Gallery;
use App\Models\GalleryItem;
use App\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\GalleryItem>
 */
class GalleryItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'gallery_id' => Gallery::factory(),
            'type' => GalleryItem::TYPE_IMAGE,
            'media_id' => Media::factory(),
            'youtube_url' => null,
            'youtube_id' => null,
            'caption' => fake()->sentence(4),
            'sort_order' => 0,
        ];
    }

    public function video(): static
    {
        return $this->state(fn () => [
            'type' => GalleryItem::TYPE_VIDEO,
            'media_id' => null,
            'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'youtube_id' => 'dQw4w9WgXcQ',
        ]);
    }
}
