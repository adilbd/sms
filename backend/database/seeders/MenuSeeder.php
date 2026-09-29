<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * The Bangla header menu, modelled on vhbub's menu and trimmed to targets this app
 * actually has (no staff, results, login, gallery, downloads or attendance lookups).
 * Only seeds the "header" location when it's empty, so re-running it never overwrites
 * an admin's edits. See docs/tasks/bangla-cms-menu.md.
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        if (MenuItem::where('location', MenuItem::LOCATION_HEADER)->exists()) {
            return;
        }

        $pageIds = Page::pluck('id', 'slug');

        $tree = [
            ['label' => 'হোম', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'home'],
            ['label' => 'আমাদের তথ্য', 'type' => MenuItem::TYPE_HEADING, 'children' => [
                ['label' => 'প্রতিষ্ঠান সম্পর্কে', 'type' => MenuItem::TYPE_HEADING, 'children' => [
                    ['label' => 'প্রতিষ্ঠানের ইতিহাস', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'history'],
                    ['label' => 'প্রতিষ্ঠান পরিচিতি', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'introduction'],
                    ['label' => 'ভৌত অবকাঠামো', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'infrastructure'],
                    ['label' => 'ভবিষ্যৎ পরিকল্পনা', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'future-plan'],
                ]],
                ['label' => 'সভাপতির বাণী', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'chairman-message'],
                ['label' => 'প্রতিষ্ঠাতার বাণী', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'founder-message'],
                ['label' => 'প্রধান শিক্ষকের বাণী', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'head-teacher-message'],
                ['label' => 'সহকারী প্রধান শিক্ষকের বাণী', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'assistant-head-message'],
                ['label' => 'আমাদের কোর্সসমূহ', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'our-courses'],
                ['label' => 'সুযোগ-সুবিধা', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'school-facilities'],
            ]],
            ['label' => 'একাডেমিক', 'type' => MenuItem::TYPE_HEADING, 'children' => [
                ['label' => 'নিয়ম-কানুন', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'rules'],
                ['label' => 'ইউনিফর্ম', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'uniform'],
                ['label' => 'সহপাঠ কার্যক্রম', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'co-curricular'],
                ['label' => 'ছুটির তালিকা', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'holidays'],
            ]],
            ['label' => 'ভর্তি', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'admissions'],
            ['label' => 'স্কুল প্রশাসন', 'type' => MenuItem::TYPE_HEADING, 'children' => [
                ['label' => 'প্রধান শিক্ষক', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'staff.head'],
                ['label' => 'সহকারী প্রধান শিক্ষক', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'staff.assistant_head'],
                ['label' => 'শিক্ষকমণ্ডলী', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'staff.teachers'],
                ['label' => 'কর্মকর্তা-কর্মচারী', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'staff.employees'],
                ['label' => 'সাবেক প্রধান শিক্ষকগণ', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'staff.ex_heads'],
                ['label' => 'সাবেক শিক্ষকগণ', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'staff.ex_teachers'],
                ['label' => 'সাবেক কর্মচারীগণ', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'staff.ex_employees'],
            ]],
            ['label' => 'সংবাদ', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'news.index'],
            ['label' => 'ইভেন্ট', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'events.index'],
            ['label' => 'গ্যালারি', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'gallery.index'],
            ['label' => 'যোগাযোগ', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'contact'],
        ];

        $this->createLevel($tree, null, $pageIds);
    }

    private function createLevel(array $nodes, ?int $parentId, $pageIds): void
    {
        foreach ($nodes as $sortOrder => $node) {
            $menuItem = MenuItem::create([
                'location' => MenuItem::LOCATION_HEADER,
                'parent_id' => $parentId,
                'label' => $node['label'],
                'type' => $node['type'],
                'page_id' => isset($node['page_slug']) ? ($pageIds[$node['page_slug']] ?? null) : null,
                'route_name' => $node['route_name'] ?? null,
                'sort_order' => $sortOrder,
            ]);

            if (! empty($node['children'])) {
                $this->createLevel($node['children'], $menuItem->id, $pageIds);
            }
        }
    }
}
