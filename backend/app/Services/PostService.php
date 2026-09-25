<?php

namespace App\Services;

use App\Models\Post;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-side queries for public content, shared by the Blade site and the public JSON API
 * so the website and the mobile app always show the same data.
 */
class PostService
{
    public function paginatePublished(string $type, int $perPage = 9): LengthAwarePaginator
    {
        $query = Post::published()->ofType($type);

        if ($type === Post::TYPE_EVENT) {
            // Upcoming events first (soonest first), then past events (most recent first).
            $query->orderByRaw('CASE WHEN event_starts_at >= ? THEN 0 ELSE 1 END', [now()->startOfDay()])
                ->orderByRaw('CASE WHEN event_starts_at >= ? THEN event_starts_at END ASC', [now()->startOfDay()])
                ->orderByDesc('event_starts_at');
        } else {
            $query->latest('published_at');
        }

        return $query->paginate($perPage);
    }

    public function findPublishedBySlug(string $type, string $slug): Post
    {
        return Post::published()->ofType($type)->where('slug', $slug)->firstOrFail();
    }

    public function latestNews(int $limit = 3): Collection
    {
        return Post::published()->ofType(Post::TYPE_NEWS)->latest('published_at')->limit($limit)->get();
    }

    public function upcomingEvents(int $limit = 3): Collection
    {
        return Post::published()->ofType(Post::TYPE_EVENT)
            ->where('event_starts_at', '>=', now()->startOfDay())
            ->orderBy('event_starts_at')
            ->limit($limit)
            ->get();
    }

    public function related(Post $post, int $limit = 3): Collection
    {
        return Post::published()->ofType($post->type)
            ->whereKeyNot($post->id)
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }
}
