<?php

namespace Tests\Unit;

use App\Models\MenuItem;
use App\Models\Page;
use App\Repositories\Contracts\MenuItemRepositoryInterface;
use App\Services\MenuService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface — no database.
 */
class MenuServiceTest extends TestCase
{
    public function test_create_is_refused_when_the_parent_would_exceed_the_maximum_depth(): void
    {
        $parent = $this->menuItem(5, ['location' => MenuItem::LOCATION_HEADER]);

        $this->mock(MenuItemRepositoryInterface::class, function (MockInterface $mock) use ($parent) {
            $mock->shouldReceive('find')->once()->with(5)->andReturn($parent);
            $mock->shouldReceive('depthOf')->once()->with($parent)->andReturn(3);
            $mock->shouldNotReceive('create');
        });

        $this->assertValidationError(
            fn () => app(MenuService::class)->create(['label' => 'দশম শাখা', 'type' => MenuItem::TYPE_HEADING, 'parent_id' => 5]),
            'parent_id'
        );
    }

    public function test_update_is_refused_when_the_parent_is_the_items_own_descendant(): void
    {
        $item = $this->menuItem(10, ['location' => MenuItem::LOCATION_HEADER, 'type' => MenuItem::TYPE_HEADING]);
        $descendant = $this->menuItem(20, ['location' => MenuItem::LOCATION_HEADER]);

        $this->mock(MenuItemRepositoryInterface::class, function (MockInterface $mock) use ($descendant, $item) {
            $mock->shouldReceive('find')->once()->with(20)->andReturn($descendant);
            $mock->shouldReceive('isDescendantOf')->once()->with($descendant, $item)->andReturn(true);
            $mock->shouldNotReceive('update');
        });

        $this->assertValidationError(
            fn () => app(MenuService::class)->update($item, ['parent_id' => 20]),
            'parent_id'
        );
    }

    public function test_update_is_refused_when_the_parent_is_the_item_itself(): void
    {
        $item = $this->menuItem(10, ['location' => MenuItem::LOCATION_HEADER, 'type' => MenuItem::TYPE_HEADING]);

        $this->mock(MenuItemRepositoryInterface::class, function (MockInterface $mock) use ($item) {
            $mock->shouldReceive('find')->once()->with(10)->andReturn($item);
            // The self check short-circuits before isDescendantOf is ever called.
            $mock->shouldNotReceive('isDescendantOf');
            $mock->shouldNotReceive('update');
        });

        $this->assertValidationError(
            fn () => app(MenuService::class)->update($item, ['parent_id' => 10]),
            'parent_id'
        );
    }

    public function test_create_requires_a_page_for_the_page_type(): void
    {
        $this->mock(MenuItemRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));

        $this->assertValidationError(
            fn () => app(MenuService::class)->create(['label' => 'ইতিহাস', 'type' => MenuItem::TYPE_PAGE]),
            'page_id'
        );
    }

    public function test_create_requires_a_route_for_the_route_type(): void
    {
        $this->mock(MenuItemRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));

        $this->assertValidationError(
            fn () => app(MenuService::class)->create(['label' => 'ভর্তি', 'type' => MenuItem::TYPE_ROUTE]),
            'route_name'
        );
    }

    public function test_create_requires_a_url_for_the_url_type(): void
    {
        $this->mock(MenuItemRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));

        $this->assertValidationError(
            fn () => app(MenuService::class)->create(['label' => 'বহিঃসংযোগ', 'type' => MenuItem::TYPE_URL]),
            'url'
        );
    }

    public function test_create_does_not_require_a_target_for_a_heading(): void
    {
        $this->mock(MenuItemRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('create')->once()->andReturn(
                $this->menuItem(1, ['type' => MenuItem::TYPE_HEADING, 'label' => 'আমাদের তথ্য'])
            );
        });

        $menuItem = app(MenuService::class)->create(['label' => 'আমাদের তথ্য', 'type' => MenuItem::TYPE_HEADING]);

        $this->assertSame(MenuItem::TYPE_HEADING, $menuItem->type);
    }

    public function test_header_tree_drops_a_page_item_whose_page_is_unpublished(): void
    {
        $publishedPage = Page::factory()->make(['is_published' => true, 'published_at' => now()->subDay(), 'slug' => 'about-page']);
        $unpublishedPage = Page::factory()->make(['is_published' => false, 'published_at' => null, 'slug' => 'draft-page']);

        $items = new Collection([
            $this->pageMenuItem(1, null, 0, 'প্রকাশিত পাতা', $publishedPage),
            $this->pageMenuItem(2, null, 1, 'অপ্রকাশিত পাতা', $unpublishedPage),
        ]);

        $this->mock(MenuItemRepositoryInterface::class, function (MockInterface $mock) use ($items) {
            $mock->shouldReceive('activeTree')->once()->with(MenuItem::LOCATION_HEADER)->andReturn($items);
        });

        $tree = app(MenuService::class)->headerTree();

        $this->assertCount(1, $tree);
        $this->assertSame('প্রকাশিত পাতা', $tree[0]['label']);
    }

    public function test_header_tree_drops_a_heading_left_with_no_visible_children(): void
    {
        $unpublishedPage = Page::factory()->make(['is_published' => false, 'published_at' => null, 'slug' => 'draft-page']);

        $heading = $this->menuItem(10, ['type' => MenuItem::TYPE_HEADING, 'label' => 'খালি হেডিং', 'sort_order' => 0]);
        $child = $this->pageMenuItem(11, 10, 0, 'অপ্রকাশিত সন্তান', $unpublishedPage);

        $items = new Collection([$heading, $child]);

        $this->mock(MenuItemRepositoryInterface::class, function (MockInterface $mock) use ($items) {
            $mock->shouldReceive('activeTree')->once()->with(MenuItem::LOCATION_HEADER)->andReturn($items);
        });

        $tree = app(MenuService::class)->headerTree();

        $this->assertSame([], $tree);
    }

    public function test_header_tree_keeps_a_heading_with_at_least_one_visible_child(): void
    {
        $publishedPage = Page::factory()->make(['is_published' => true, 'published_at' => now()->subDay(), 'slug' => 'history']);

        $heading = $this->menuItem(10, ['type' => MenuItem::TYPE_HEADING, 'label' => 'আমাদের তথ্য', 'sort_order' => 0]);
        $child = $this->pageMenuItem(11, 10, 0, 'প্রতিষ্ঠানের ইতিহাস', $publishedPage);

        $items = new Collection([$heading, $child]);

        $this->mock(MenuItemRepositoryInterface::class, function (MockInterface $mock) use ($items) {
            $mock->shouldReceive('activeTree')->once()->with(MenuItem::LOCATION_HEADER)->andReturn($items);
        });

        $tree = app(MenuService::class)->headerTree();

        $this->assertCount(1, $tree);
        $this->assertSame('আমাদের তথ্য', $tree[0]['label']);
        $this->assertCount(1, $tree[0]['children']);
        $this->assertSame('প্রতিষ্ঠানের ইতিহাস', $tree[0]['children'][0]['label']);
    }

    private function menuItem(int $id, array $attributes = []): MenuItem
    {
        $item = new MenuItem(array_merge([
            'location' => MenuItem::LOCATION_HEADER,
            'type' => MenuItem::TYPE_ROUTE,
            'route_name' => 'home',
            'sort_order' => 0,
            'is_active' => true,
            'open_in_new_tab' => false,
        ], $attributes));
        $item->id = $id;

        return $item;
    }

    private function pageMenuItem(int $id, ?int $parentId, int $sortOrder, string $label, Page $page): MenuItem
    {
        $item = $this->menuItem($id, [
            'parent_id' => $parentId,
            'type' => MenuItem::TYPE_PAGE,
            'route_name' => null,
            'label' => $label,
            'sort_order' => $sortOrder,
        ]);
        $item->setRelation('page', $page);

        return $item;
    }

    private function assertValidationError(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }
}
