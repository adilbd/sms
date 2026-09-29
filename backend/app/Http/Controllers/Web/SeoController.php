<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\GalleryService;
use App\Services\PageService;
use App\Services\StaffService;
use Illuminate\Support\Facades\Cache;

class SeoController extends Controller
{
    public function __construct(
        private PageService $pages,
        private GalleryService $galleries,
        private StaffService $staff,
    ) {}

    public function sitemap()
    {
        $xml = Cache::remember('sitemap.xml', now()->addHour(), function () {
            $latest = Post::published()->max('updated_at');

            $urls = collect([
                ['loc' => route('home'), 'changefreq' => 'weekly', 'priority' => '1.0', 'lastmod' => $latest],
                ['loc' => route('about'), 'changefreq' => 'monthly', 'priority' => '0.7'],
                ['loc' => route('admissions'), 'changefreq' => 'monthly', 'priority' => '0.9'],
                ['loc' => route('contact'), 'changefreq' => 'yearly', 'priority' => '0.6'],
                ['loc' => route('news.index'), 'changefreq' => 'daily', 'priority' => '0.8',
                    'lastmod' => Post::published()->ofType(Post::TYPE_NEWS)->max('updated_at')],
                ['loc' => route('events.index'), 'changefreq' => 'daily', 'priority' => '0.8',
                    'lastmod' => Post::published()->ofType(Post::TYPE_EVENT)->max('updated_at')],
                ['loc' => route('gallery.index'), 'changefreq' => 'weekly', 'priority' => '0.7',
                    'lastmod' => $this->galleries->latestPublishedUpdatedAt()],
                ['loc' => route('staff.head'), 'changefreq' => 'monthly', 'priority' => '0.6'],
                ['loc' => route('staff.assistant_head'), 'changefreq' => 'monthly', 'priority' => '0.6'],
                ['loc' => route('staff.teachers'), 'changefreq' => 'monthly', 'priority' => '0.6'],
                ['loc' => route('staff.employees'), 'changefreq' => 'monthly', 'priority' => '0.6'],
                ['loc' => route('staff.ex_heads'), 'changefreq' => 'yearly', 'priority' => '0.3'],
                ['loc' => route('staff.ex_teachers'), 'changefreq' => 'yearly', 'priority' => '0.3'],
                ['loc' => route('staff.ex_employees'), 'changefreq' => 'yearly', 'priority' => '0.3'],
            ]);

            Post::published()->orderByDesc('published_at')
                ->select(['id', 'type', 'slug', 'updated_at'])
                ->chunk(500, function ($posts) use (&$urls) {
                    foreach ($posts as $post) {
                        $urls->push([
                            'loc' => $post->url(),
                            'lastmod' => $post->updated_at,
                            'changefreq' => 'monthly',
                            'priority' => '0.6',
                        ]);
                    }
                });

            foreach ($this->pages->publishedForSitemap() as $page) {
                $urls->push([
                    'loc' => $page->url(),
                    'lastmod' => $page->updated_at,
                    'changefreq' => 'monthly',
                    'priority' => '0.5',
                ]);
            }

            foreach ($this->galleries->publishedForSitemap() as $gallery) {
                $urls->push([
                    'loc' => $gallery->url(),
                    'lastmod' => $gallery->updated_at,
                    'changefreq' => 'monthly',
                    'priority' => '0.5',
                ]);
            }

            foreach ($this->staff->publishedForSitemap() as $member) {
                $urls->push([
                    'loc' => $member->url(),
                    'lastmod' => $member->updated_at,
                    'changefreq' => 'yearly',
                    'priority' => '0.4',
                ]);
            }

            return view('seo.sitemap', ['urls' => $urls])->render();
        });

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    public function robots()
    {
        $lines = app()->isProduction()
            ? ['User-agent: *', 'Allow: /', 'Disallow: /admin', 'Disallow: /api', '', 'Sitemap: '.route('sitemap')]
            // Keep non-production environments (staging, local) out of search indexes.
            : ['User-agent: *', 'Disallow: /'];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
