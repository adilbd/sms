<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Services\PostService;
use App\Support\SchemaOrg;

class PostController extends Controller
{
    public function __construct(private PostService $posts)
    {
    }

    public function newsIndex()
    {
        return $this->index(Post::TYPE_NEWS);
    }

    public function newsShow(string $slug)
    {
        return $this->show(Post::TYPE_NEWS, $slug);
    }

    public function eventsIndex()
    {
        return $this->index(Post::TYPE_EVENT);
    }

    public function eventsShow(string $slug)
    {
        return $this->show(Post::TYPE_EVENT, $slug);
    }

    private function index(string $type)
    {
        $section = $this->section($type);
        $posts = $this->posts->paginatePublished($type);

        // Out-of-range pages are not real content.
        abort_if($posts->currentPage() > 1 && $posts->isEmpty(), 404);

        return view('public.posts.index', [
            'type' => $type,
            'section' => $section,
            'posts' => $posts,
            'jsonLd' => [SchemaOrg::breadcrumbs([['Home', route('home')], [$section['title'], route($section['route'])]])],
        ]);
    }

    private function show(string $type, string $slug)
    {
        $section = $this->section($type);
        $post = $this->posts->findPublishedBySlug($type, $slug);

        return view('public.posts.show', [
            'type' => $type,
            'section' => $section,
            'post' => $post,
            'related' => $this->posts->related($post),
            'jsonLd' => [
                SchemaOrg::post($post),
                SchemaOrg::breadcrumbs([
                    ['Home', route('home')],
                    [$section['title'], route($section['route'])],
                    [$post->title, $post->url()],
                ]),
            ],
        ]);
    }

    private function section(string $type): array
    {
        return $type === Post::TYPE_EVENT
            ? ['title' => 'Events', 'route' => 'events.index', 'description' => 'Upcoming and past school events: open days, sports days, concerts, parent meetings and more.']
            : ['title' => 'News', 'route' => 'news.index', 'description' => 'The latest news, achievements and announcements from our school community.'];
    }
}
