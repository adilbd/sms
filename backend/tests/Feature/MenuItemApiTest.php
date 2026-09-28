<?php

namespace Tests\Feature;

use App\Models\MenuItem;
use App\Models\Page;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class MenuItemApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@sms.com')->firstOrFail();
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/menu-items')->assertUnauthorized();
    }

    public function test_teacher_student_and_parent_are_forbidden_from_every_action(): void
    {
        $item = MenuItem::factory()->create();

        foreach (['teacher', 'student', 'parent'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user, 'sanctum')->getJson('/api/menu-items')->assertForbidden();
            $this->actingAs($user, 'sanctum')->getJson("/api/menu-items/{$item->id}")->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson('/api/menu-items', [])->assertForbidden();
            $this->actingAs($user, 'sanctum')->putJson("/api/menu-items/{$item->id}", [])->assertForbidden();
            $this->actingAs($user, 'sanctum')->deleteJson("/api/menu-items/{$item->id}")->assertForbidden();
            $this->actingAs($user, 'sanctum')->putJson('/api/menu-items/reorder', ['items' => []])->assertForbidden();
        }
    }

    public function test_index_returns_paginated_resources(): void
    {
        MenuItem::factory()->count(3)->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/menu-items')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'location', 'parent_id', 'label', 'type', 'page_id', 'route_name', 'url', 'href', 'sort_order', 'is_active', 'open_in_new_tab', 'created_at', 'updated_at']],
                'links',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.total', 3);
    }

    public function test_store_creates_a_page_item_with_resolved_href(): void
    {
        $page = Page::factory()->create(['slug' => 'about-us']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => 'আমাদের সম্পর্কে', 'type' => 'page', 'page_id' => $page->id])
            ->assertCreated()
            ->assertJsonPath('data.type', 'page')
            ->assertJsonPath('data.href', $page->url())
            ->assertJsonPath('message', 'Menu item created successfully');
    }

    public function test_show_nests_the_linked_page_as_a_page_resource(): void
    {
        $page = Page::factory()->create(['slug' => 'about-us', 'title' => 'আমাদের সম্পর্কে']);
        $item = MenuItem::factory()->page($page->id)->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/menu-items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('data.page.id', $page->id)
            ->assertJsonPath('data.page.title', 'আমাদের সম্পর্কে')
            ->assertJsonPath('data.page.slug', 'about-us')
            ->assertJsonPath('data.page.web_url', $page->url());
    }

    public function test_index_returns_a_null_page_for_an_item_with_no_linked_page(): void
    {
        MenuItem::factory()->heading()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/menu-items')
            ->assertOk()
            ->assertJsonPath('data.0.page', null);
    }

    public function test_store_creates_a_route_item_with_resolved_href(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => 'ভর্তি', 'type' => 'route', 'route_name' => 'admissions'])
            ->assertCreated()
            ->assertJsonPath('data.href', route('admissions'));
    }

    public function test_store_creates_a_url_item(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => 'বহিঃসংযোগ', 'type' => 'url', 'url' => 'https://example.com/path'])
            ->assertCreated()
            ->assertJsonPath('data.href', 'https://example.com/path');
    }

    public function test_store_creates_a_heading_item_with_no_href(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => 'আমাদের তথ্য', 'type' => 'heading'])
            ->assertCreated()
            ->assertJsonPath('data.href', null)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.open_in_new_tab', false);
    }

    public function test_show_returns_a_menu_item(): void
    {
        $item = MenuItem::factory()->create(['label' => 'হোম']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/menu-items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('data.label', 'হোম');
    }

    public function test_update_returns_200(): void
    {
        $item = MenuItem::factory()->create(['label' => 'পুরাতন লেবেল']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/menu-items/{$item->id}", ['label' => 'নতুন লেবেল'])
            ->assertOk()
            ->assertJsonPath('data.label', 'নতুন লেবেল')
            ->assertJsonPath('message', 'Menu item updated successfully');
    }

    public function test_destroy_returns_no_content_and_deletes_children_too(): void
    {
        $parent = MenuItem::factory()->heading()->create();
        $child = MenuItem::factory()->create(['parent_id' => $parent->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/menu-items/{$parent->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('menu_items', ['id' => $parent->id]);
        $this->assertDatabaseMissing('menu_items', ['id' => $child->id]);
    }

    public function test_unknown_menu_item_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/menu-items/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Record not found.']);
    }

    public function test_non_numeric_id_does_not_resolve_a_menu_item(): void
    {
        $item = MenuItem::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/menu-items/{$item->id}abc")
            ->assertNotFound();
    }

    public function test_store_rejects_an_invalid_type(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => 'X', 'type' => 'invalid'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');
    }

    public function test_store_rejects_a_javascript_url(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => 'X', 'type' => 'url', 'url' => 'javascript:alert(1)'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
    }

    public function test_store_rejects_an_unknown_route(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => 'X', 'type' => 'route', 'route_name' => 'students.index'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('route_name');
    }

    public function test_store_requires_a_page_id_for_the_page_type(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => 'X', 'type' => 'page'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page_id');
    }

    public function test_store_rejects_a_nonexistent_page_id(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => 'X', 'type' => 'page', 'page_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('page_id');
    }

    public function test_store_rejects_a_label_over_255_characters(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => str_repeat('a', 256), 'type' => 'heading'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('label');
    }

    public function test_store_rejects_a_negative_sort_order(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => 'X', 'type' => 'heading', 'sort_order' => -1])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort_order');
    }

    public function test_store_rejects_a_protocol_relative_url(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => 'X', 'type' => 'url', 'url' => '//evil.example'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
    }

    public function test_store_rejects_a_backslash_prefixed_url(): void
    {
        // Some browsers treat a leading backslash the same as a slash, so
        // "/\evil.com" would otherwise behave like the protocol-relative "//evil.com".
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => 'X', 'type' => 'url', 'url' => '/\\evil.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
    }

    public function test_store_defaults_sort_order_to_the_highest_sibling_plus_one(): void
    {
        MenuItem::factory()->heading()->create(['parent_id' => null, 'sort_order' => 0]);
        MenuItem::factory()->heading()->create(['parent_id' => null, 'sort_order' => 3]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => 'নতুন শীর্ষ আইটেম', 'type' => 'heading'])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 4);
    }

    public function test_store_defaults_sort_order_to_zero_for_the_first_item_under_a_parent(): void
    {
        $parent = MenuItem::factory()->heading()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => 'প্রথম সন্তান', 'type' => 'route', 'route_name' => 'home', 'parent_id' => $parent->id])
            ->assertCreated()
            ->assertJsonPath('data.sort_order', 0);
    }

    public function test_store_rejects_a_parent_deeper_than_the_maximum_depth(): void
    {
        $level1 = MenuItem::factory()->heading()->create();
        $level2 = MenuItem::factory()->heading()->create(['parent_id' => $level1->id]);
        $level3 = MenuItem::factory()->create(['parent_id' => $level2->id, 'type' => 'route', 'route_name' => 'home']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/menu-items', ['label' => 'চতুর্থ স্তর', 'type' => 'route', 'route_name' => 'contact', 'parent_id' => $level3->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_update_rejects_moving_an_item_under_its_own_descendant(): void
    {
        $parent = MenuItem::factory()->heading()->create();
        $child = MenuItem::factory()->heading()->create(['parent_id' => $parent->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/menu-items/{$parent->id}", ['parent_id' => $child->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_update_rejects_an_item_as_its_own_parent(): void
    {
        $item = MenuItem::factory()->heading()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/menu-items/{$item->id}", ['parent_id' => $item->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_update_rejects_moving_an_item_with_children_under_a_level_two_item(): void
    {
        // level1 (depth 1) > level2 (depth 2). $movedItem has its own child, so its
        // subtree is 2 levels tall; nesting it under level2 would push its child to
        // depth 4, past MenuItem::MAX_DEPTH.
        $level1 = MenuItem::factory()->heading()->create();
        $level2 = MenuItem::factory()->heading()->create(['parent_id' => $level1->id]);

        $movedItem = MenuItem::factory()->heading()->create();
        MenuItem::factory()->create(['parent_id' => $movedItem->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/menu-items/{$movedItem->id}", ['parent_id' => $level2->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parent_id');
    }

    public function test_update_defaults_sort_order_to_the_new_siblings_max_plus_one_when_parent_changes(): void
    {
        $oldParent = MenuItem::factory()->heading()->create();
        $newParent = MenuItem::factory()->heading()->create();
        MenuItem::factory()->create(['parent_id' => $newParent->id, 'sort_order' => 2]);
        $item = MenuItem::factory()->create(['parent_id' => $oldParent->id, 'sort_order' => 0]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/menu-items/{$item->id}", ['parent_id' => $newParent->id])
            ->assertOk()
            ->assertJsonPath('data.sort_order', 3);

        $this->assertDatabaseHas('menu_items', ['id' => $item->id, 'parent_id' => $newParent->id, 'sort_order' => 3]);
    }

    public function test_reorder_rejects_a_row_missing_parent_id(): void
    {
        $item = MenuItem::factory()->heading()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/menu-items/reorder', [
                'items' => [
                    ['id' => $item->id, 'sort_order' => 0],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.parent_id');

        $this->assertDatabaseHas('menu_items', ['id' => $item->id, 'parent_id' => null]);
    }

    public function test_reorder_persists_new_sort_order_and_parent_id(): void
    {
        $parentA = MenuItem::factory()->heading()->create(['sort_order' => 0]);
        $parentB = MenuItem::factory()->heading()->create(['sort_order' => 1]);
        $child = MenuItem::factory()->create(['parent_id' => $parentA->id, 'sort_order' => 0]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/menu-items/reorder', [
                'items' => [
                    ['id' => $parentA->id, 'parent_id' => null, 'sort_order' => 1],
                    ['id' => $parentB->id, 'parent_id' => null, 'sort_order' => 0],
                    ['id' => $child->id, 'parent_id' => $parentB->id, 'sort_order' => 0],
                ],
            ])
            ->assertOk();

        $this->assertDatabaseHas('menu_items', ['id' => $parentA->id, 'sort_order' => 1, 'parent_id' => null]);
        $this->assertDatabaseHas('menu_items', ['id' => $parentB->id, 'sort_order' => 0, 'parent_id' => null]);
        $this->assertDatabaseHas('menu_items', ['id' => $child->id, 'sort_order' => 0, 'parent_id' => $parentB->id]);
    }

    public function test_reorder_rejects_a_batch_that_creates_a_cycle_between_two_items(): void
    {
        $itemA = MenuItem::factory()->heading()->create();
        $itemB = MenuItem::factory()->heading()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/menu-items/reorder', [
                'items' => [
                    ['id' => $itemA->id, 'parent_id' => $itemB->id, 'sort_order' => 0],
                    ['id' => $itemB->id, 'parent_id' => $itemA->id, 'sort_order' => 0],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertDatabaseHas('menu_items', ['id' => $itemA->id, 'parent_id' => null]);
        $this->assertDatabaseHas('menu_items', ['id' => $itemB->id, 'parent_id' => null]);
    }

    public function test_reorder_rejects_a_row_naming_itself_as_its_own_parent(): void
    {
        $item = MenuItem::factory()->heading()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/menu-items/reorder', [
                'items' => [
                    ['id' => $item->id, 'parent_id' => $item->id, 'sort_order' => 0],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');
    }

    public function test_reorder_rejects_a_batch_that_would_exceed_the_maximum_depth(): void
    {
        // level1 > level2 > leaf (depth 3, the maximum). $movedItem has a child of
        // its own, so moving it under leaf would push that child to depth 5.
        $level1 = MenuItem::factory()->heading()->create();
        $level2 = MenuItem::factory()->heading()->create(['parent_id' => $level1->id]);
        $leaf = MenuItem::factory()->create(['parent_id' => $level2->id, 'type' => 'route', 'route_name' => 'home']);

        $movedItem = MenuItem::factory()->heading()->create();
        $movedChild = MenuItem::factory()->create(['parent_id' => $movedItem->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/menu-items/reorder', [
                'items' => [
                    ['id' => $movedItem->id, 'parent_id' => $leaf->id, 'sort_order' => 0],
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items');

        $this->assertDatabaseHas('menu_items', ['id' => $movedItem->id, 'parent_id' => null]);
        $this->assertDatabaseHas('menu_items', ['id' => $movedChild->id, 'parent_id' => $movedItem->id]);
    }

    public function test_menu_and_page_changes_invalidate_the_header_cache(): void
    {
        $page = Page::factory()->create(['is_published' => false, 'published_at' => null, 'title' => 'গোপন পাতা']);

        $item = MenuItem::factory()->page($page->id)->create(['label' => 'গোপন পাতার লিংক']);

        // Hidden while the page is unpublished.
        $this->get('/')->assertDontSee('গোপন পাতার লিংক');

        $page->update(['is_published' => true, 'published_at' => now()->subMinute()]);

        $this->get('/')->assertSee('গোপন পাতার লিংক');

        $item->update(['is_active' => false]);

        $this->get('/')->assertDontSee('গোপন পাতার লিংক');
    }

    protected function tearDown(): void
    {
        Cache::forget('menu.header');

        parent::tearDown();
    }
}
