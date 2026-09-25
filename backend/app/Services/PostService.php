<?php

namespace App\Services;

use App\Models\Post;
use App\Models\User;
use App\Repositories\Contracts\PostRepositoryInterface;
use App\Support\PostBody;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Public read queries (shared by the Blade site and the unauthenticated mobile-app API,
 * so both always show the same data) plus the admin CRUD used by Api\PostController.
 */
class PostService
{
    public function __construct(private PostRepositoryInterface $posts) {}

    public function paginatePublished(string $type, int $perPage = 9): LengthAwarePaginator
    {
        return $this->posts->paginatePublished($type, $perPage);
    }

    public function findPublishedBySlug(string $type, string $slug): Post
    {
        return $this->posts->findPublishedBySlug($type, $slug);
    }

    public function latestNews(int $limit = 3): Collection
    {
        return $this->posts->latestNews($limit);
    }

    public function upcomingEvents(int $limit = 3): Collection
    {
        return $this->posts->upcomingEvents($limit);
    }

    public function related(Post $post, int $limit = 3): Collection
    {
        return $this->posts->related($post, $limit);
    }

    /**
     * @param  array{type?: string, search?: string, is_published?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->posts->paginate($filters, $perPage);
    }

    public function create(array $data, User $author): Post
    {
        $data['body'] = $this->sanitizedBody($data['body']);
        $data['author_id'] = $author->id;

        return $this->posts->create($data);
    }

    public function update(Post $post, array $data): Post
    {
        if (array_key_exists('body', $data)) {
            $data['body'] = $this->sanitizedBody($data['body']);
        }

        $this->ensureEventDatesAreValid((clone $post)->fill($data));

        return $this->posts->update($post, $data);
    }

    public function delete(Post $post): void
    {
        $this->posts->delete($post);
    }

    /**
     * Sanitize the body with the post_body HTMLPurifier profile, and reject a body that
     * would render as nothing (no text, no img, no iframe).
     */
    private function sanitizedBody(string $body): string
    {
        $sanitized = PostBody::sanitize($body);

        if (PostBody::isEmpty($sanitized)) {
            throw ValidationException::withMessages([
                'body' => ['The body must not be empty.'],
            ]);
        }

        return $sanitized;
    }

    /**
     * An event requires event_starts_at, and event_ends_at (when set) must not be
     * before it. Checked here, against the model with the update applied, because a
     * partial update can send only one of the two dates (see UpdatePostRequest).
     */
    private function ensureEventDatesAreValid(Post $post): void
    {
        if ($post->type !== Post::TYPE_EVENT) {
            return;
        }

        if (! $post->event_starts_at) {
            throw ValidationException::withMessages([
                'event_starts_at' => ['The event starts at field is required.'],
            ]);
        }

        if ($post->event_ends_at && $post->event_ends_at->lt($post->event_starts_at)) {
            throw ValidationException::withMessages([
                'event_ends_at' => ['The event ends at field must be a date after or equal to event starts at.'],
            ]);
        }
    }
}
