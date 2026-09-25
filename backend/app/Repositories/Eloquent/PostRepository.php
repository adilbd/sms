<?php

namespace App\Repositories\Eloquent;

use App\Models\Post;
use App\Repositories\Contracts\PostRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class PostRepository extends EloquentRepository implements PostRepositoryInterface
{
    protected string $model = Post::class;

    public function paginatePublished(string $type, int $perPage): LengthAwarePaginator
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

    public function latestNews(int $limit): Collection
    {
        return Post::published()->ofType(Post::TYPE_NEWS)->latest('published_at')->limit($limit)->get();
    }

    public function upcomingEvents(int $limit): Collection
    {
        return Post::published()->ofType(Post::TYPE_EVENT)
            ->where('event_starts_at', '>=', now()->startOfDay())
            ->orderBy('event_starts_at')
            ->limit($limit)
            ->get();
    }

    public function related(Post $post, int $limit): Collection
    {
        return Post::published()->ofType($post->type)
            ->whereKeyNot($post->id)
            ->latest('published_at')
            ->limit($limit)
            ->get();
    }

    protected function query(): Builder
    {
        return parent::query()->latest()->orderBy('id', 'desc');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        if (filled($filters['type'] ?? null)) {
            $query->ofType($filters['type']);
        }

        if (filled($filters['search'] ?? null)) {
            $query->where('title', 'like', '%'.$filters['search'].'%');
        }

        if (filled($filters['is_published'] ?? null)) {
            $query->where('is_published', filter_var($filters['is_published'], FILTER_VALIDATE_BOOLEAN));
        }

        return $query;
    }
}
