<?php

namespace Database\Factories;

use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Post>
 */
class PostFactory extends Factory
{
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'type' => Post::TYPE_NEWS,
            'title' => $title,
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 100000),
            'excerpt' => fake()->sentence(),
            'body' => '<p>'.fake()->paragraph().'</p>',
            'cover_image' => null,
            'event_starts_at' => null,
            'event_ends_at' => null,
            'location' => null,
            'meta_title' => null,
            'meta_description' => null,
            'is_published' => true,
            'published_at' => now()->subDay(),
            'author_id' => null,
        ];
    }

    public function event(): static
    {
        return $this->state(function () {
            $start = now()->addWeek()->setTime(10, 0);

            return [
                'type' => Post::TYPE_EVENT,
                'event_starts_at' => $start,
                'event_ends_at' => $start->copy()->addHours(2),
                'location' => 'Main Hall',
            ];
        });
    }

    public function draft(): static
    {
        return $this->state(fn () => [
            'is_published' => false,
            'published_at' => null,
        ]);
    }
}
