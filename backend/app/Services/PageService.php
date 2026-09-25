<?php

namespace App\Services;

use App\Models\Page;
use App\Repositories\Contracts\PageRepositoryInterface;
use App\Support\PostBody;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Admin CRUD for standalone content pages, plus the read used by the public
 * Web\PageController and the sitemap. Bodies are sanitized HTML, same as Post
 * (see App\Support\PostBody).
 */
class PageService
{
    public function __construct(private PageRepositoryInterface $pages) {}

    /**
     * @param  array{search?: string, is_published?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->pages->paginate($filters, $perPage);
    }

    public function create(array $data): Page
    {
        $data['body'] = $this->sanitizedBody($data['body']);

        return $this->pages->create($data);
    }

    public function update(Page $page, array $data): Page
    {
        if (array_key_exists('body', $data)) {
            $data['body'] = $this->sanitizedBody($data['body']);
        }

        return $this->pages->update($page, $data);
    }

    public function delete(Page $page): void
    {
        $this->pages->delete($page);
    }

    public function findPublishedBySlug(string $slug): Page
    {
        return $this->pages->findPublishedBySlug($slug);
    }

    public function publishedForSitemap(): Collection
    {
        return $this->pages->publishedForSitemap();
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
}
