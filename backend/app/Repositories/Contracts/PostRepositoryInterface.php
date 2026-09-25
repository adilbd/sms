<?php

namespace App\Repositories\Contracts;

use App\Models\Post;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface PostRepositoryInterface extends RepositoryInterface
{
    /**
     * Published posts of one type, newest first for news, soonest-upcoming-first for events.
     */
    public function paginatePublished(string $type, int $perPage): LengthAwarePaginator;

    public function findPublishedBySlug(string $type, string $slug): Post;

    public function latestNews(int $limit): Collection;

    public function upcomingEvents(int $limit): Collection;

    public function related(Post $post, int $limit): Collection;
}
