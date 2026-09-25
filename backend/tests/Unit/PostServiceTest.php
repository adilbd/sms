<?php

namespace Tests\Unit;

use App\Models\Post;
use App\Models\User;
use App\Repositories\Contracts\PostRepositoryInterface;
use App\Services\PostService;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface — no database.
 */
class PostServiceTest extends TestCase
{
    public function test_create_sanitizes_the_body_and_sets_the_author(): void
    {
        $author = new User(['name' => 'Admin']);
        $author->id = 7;

        $this->mock(PostRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('create')
                ->once()
                ->withArgs(function (array $data) {
                    return $data['body'] === '<p>Hello</p>'
                        && $data['author_id'] === 7
                        && ! str_contains($data['body'], '<script');
                })
                ->andReturn(new Post);
        });

        app(PostService::class)->create([
            'type' => 'news',
            'title' => 'Hello',
            'body' => '<script>alert(1)</script><p>Hello</p>',
        ], $author);
    }

    public function test_update_sanitizes_the_body(): void
    {
        $post = new Post;

        $this->mock(PostRepositoryInterface::class, function (MockInterface $mock) use ($post) {
            $mock->shouldReceive('update')
                ->once()
                ->withArgs(fn ($model, array $data) => $model === $post && $data['body'] === '<p>Updated</p>')
                ->andReturn($post);
        });

        app(PostService::class)->update($post, ['body' => '<p onclick="x()">Updated</p>']);
    }

    public function test_update_without_a_body_does_not_touch_it(): void
    {
        $post = new Post;

        $this->mock(PostRepositoryInterface::class, function (MockInterface $mock) use ($post) {
            $mock->shouldReceive('update')
                ->once()
                ->withArgs(fn ($model, array $data) => ! array_key_exists('body', $data))
                ->andReturn($post);
        });

        app(PostService::class)->update($post, ['title' => 'New title']);
    }

    public function test_create_rejects_a_body_that_is_empty_after_sanitizing(): void
    {
        $this->mock(PostRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('create');
        });

        try {
            app(PostService::class)->create([
                'type' => 'news',
                'title' => 'Hello',
                'body' => '<p></p>',
            ], new User);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
        }
    }

    public function test_update_rejects_a_body_that_is_empty_after_sanitizing(): void
    {
        $post = new Post;

        $this->mock(PostRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('update');
        });

        try {
            app(PostService::class)->update($post, ['body' => '<script>alert(1)</script>']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
        }
    }

    public function test_update_rejects_turning_a_post_into_an_event_without_a_start_date(): void
    {
        $post = new Post(['type' => Post::TYPE_NEWS]);

        $this->mock(PostRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('update');
        });

        try {
            app(PostService::class)->update($post, ['type' => 'event']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('event_starts_at', $e->errors());
        }
    }

    public function test_update_rejects_an_end_date_before_the_saved_start_date(): void
    {
        $post = new Post([
            'type' => Post::TYPE_EVENT,
            'event_starts_at' => '2026-10-10 10:00:00',
        ]);

        $this->mock(PostRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('update');
        });

        try {
            app(PostService::class)->update($post, ['event_ends_at' => '2026-10-09 10:00:00']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('event_ends_at', $e->errors());
        }
    }

    public function test_update_accepts_a_valid_ends_only_update(): void
    {
        $post = new Post([
            'type' => Post::TYPE_EVENT,
            'event_starts_at' => '2026-10-10 10:00:00',
        ]);

        $this->mock(PostRepositoryInterface::class, function (MockInterface $mock) use ($post) {
            $mock->shouldReceive('update')
                ->once()
                ->withArgs(fn ($model, array $data) => $model === $post && $data['event_ends_at'] === '2026-10-10 12:00:00')
                ->andReturn($post);
        });

        app(PostService::class)->update($post, ['event_ends_at' => '2026-10-10 12:00:00']);
    }
}
