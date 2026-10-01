<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * The Bangla header menu, modelled on vhbub's menu and trimmed to targets this app
 * actually has (no login, downloads or attendance lookups). Only seeds the "header"
 * location when it's empty, so re-running it never overwrites an admin's edits; the exceptions
 * are the ফলাফল (Results) and লগইন / পোর্টাল items, each added once to an existing menu. See docs/tasks/bangla-cms-menu.md.
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        if (MenuItem::where('location', MenuItem::LOCATION_HEADER)->exists()) {
            $this->ensureResultsItem();
            $this->ensurePortalItem();

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
            ['label' => 'স্কুল প্রশাসন', 'type' => MenuItem::TYPE_HEADING, 'children' => [
                ['label' => 'প্রধান শিক্ষক', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'staff.head'],
                ['label' => 'সহকারী প্রধান শিক্ষক', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'staff.assistant_head'],
                ['label' => 'শিক্ষকমণ্ডলী', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'staff.teachers'],
                ['label' => 'কর্মকর্তা-কর্মচারী', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'staff.employees'],
                ['label' => 'সাবেক প্রধান শিক্ষকগণ', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'staff.ex_heads'],
                ['label' => 'সাবেক শিক্ষকগণ', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'staff.ex_teachers'],
                ['label' => 'সাবেক কর্মচারীগণ', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'staff.ex_employees'],
            ]],
            ['label' => 'একাডেমিক', 'type' => MenuItem::TYPE_HEADING, 'children' => [
                ['label' => 'নিয়ম-কানুন', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'rules'],
                ['label' => 'ইউনিফর্ম', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'uniform'],
                ['label' => 'সহপাঠ কার্যক্রম', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'co-curricular'],
                ['label' => 'ছুটির তালিকা', 'type' => MenuItem::TYPE_PAGE, 'page_slug' => 'holidays'],
            ]],
            ['label' => 'ভর্তি', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'admissions'],
            ['label' => 'সংবাদ', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'news.index'],
            ['label' => 'ইভেন্ট', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'events.index'],
            ['label' => 'গ্যালারি', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'gallery.index'],
            ['label' => 'ফলাফল', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'results.index'],
            ['label' => 'যোগাযোগ', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'contact'],
            ['label' => 'লগইন / পোর্টাল', 'type' => MenuItem::TYPE_ROUTE, 'route_name' => 'portal.login'],
        ];

        $this->createLevel($tree, null, $pageIds);
    }

    /**
     * A menu seeded before the public result lookup existed gets the ফলাফল item once, at
     * the end of the top level. Matched by route, so re-running never adds a second one,
     * and nothing else in an admin's edited menu is touched.
     */
    private function ensureResultsItem(): void
    {
        $exists = MenuItem::where('location', MenuItem::LOCATION_HEADER)
            ->where('type', MenuItem::TYPE_ROUTE)
            ->where('route_name', 'results.index')
            ->exists();

        if ($exists) {
            return;
        }

        $last = MenuItem::where('location', MenuItem::LOCATION_HEADER)->whereNull('parent_id')->max('sort_order');

        MenuItem::create([
            'location' => MenuItem::LOCATION_HEADER,
            'parent_id' => null,
            'label' => 'ফলাফল',
            'type' => MenuItem::TYPE_ROUTE,
            'route_name' => 'results.index',
            'sort_order' => ($last ?? -1) + 1,
        ]);
    }

    /**
     * Same as ensureResultsItem(): the student/guardian portal link, added once at the end
     * of the top level of a menu seeded before the portal existed.
     */
    private function ensurePortalItem(): void
    {
        $exists = MenuItem::where('location', MenuItem::LOCATION_HEADER)
            ->where('type', MenuItem::TYPE_ROUTE)
            ->where('route_name', 'portal.login')
            ->exists();

        if ($exists) {
            return;
        }

        $last = MenuItem::where('location', MenuItem::LOCATION_HEADER)->whereNull('parent_id')->max('sort_order');

        MenuItem::create([
            'location' => MenuItem::LOCATION_HEADER,
            'parent_id' => null,
            'label' => 'লগইন / পোর্টাল',
            'type' => MenuItem::TYPE_ROUTE,
            'route_name' => 'portal.login',
            'sort_order' => ($last ?? -1) + 1,
        ]);
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
