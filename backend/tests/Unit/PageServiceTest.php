<?php

namespace Tests\Unit;

use App\Models\Page;
use App\Repositories\Contracts\PageRepositoryInterface;
use App\Services\PageService;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface — no database.
 */
class PageServiceTest extends TestCase
{
    public function test_create_sanitizes_the_body(): void
    {
        $this->mock(PageRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('create')
                ->once()
                ->withArgs(fn (array $data) => $data['body'] === '<p>Hello</p>' && ! str_contains($data['body'], '<script'))
                ->andReturn(new Page);
        });

        app(PageService::class)->create([
            'title' => 'Hello',
            'body' => '<script>alert(1)</script><p>Hello</p>',
        ]);
    }

    public function test_update_sanitizes_the_body(): void
    {
        $page = new Page;

        $this->mock(PageRepositoryInterface::class, function (MockInterface $mock) use ($page) {
            $mock->shouldReceive('update')
                ->once()
                ->withArgs(fn ($model, array $data) => $model === $page && $data['body'] === '<p>Updated</p>')
                ->andReturn($page);
        });

        app(PageService::class)->update($page, ['body' => '<p onclick="x()">Updated</p>']);
    }

    public function test_update_without_a_body_does_not_touch_it(): void
    {
        $page = new Page;

        $this->mock(PageRepositoryInterface::class, function (MockInterface $mock) use ($page) {
            $mock->shouldReceive('update')
                ->once()
                ->withArgs(fn ($model, array $data) => ! array_key_exists('body', $data))
                ->andReturn($page);
        });

        app(PageService::class)->update($page, ['title' => 'New title']);
    }

    public function test_create_rejects_a_body_that_is_empty_after_sanitizing(): void
    {
        $this->mock(PageRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('create');
        });

        try {
            app(PageService::class)->create([
                'title' => 'Hello',
                'body' => '<p></p>',
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
        }
    }

    public function test_update_rejects_a_body_that_is_empty_after_sanitizing(): void
    {
        $page = new Page;

        $this->mock(PageRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldNotReceive('update');
        });

        try {
            app(PageService::class)->update($page, ['body' => '<script>alert(1)</script>']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('body', $e->errors());
        }
    }
}
