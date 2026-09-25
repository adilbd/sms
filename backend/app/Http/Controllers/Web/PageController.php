<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\PageService;
use App\Support\SchemaOrg;

class PageController extends Controller
{
    public function __construct(private PageService $pages) {}

    public function show(string $slug)
    {
        $page = $this->pages->findPublishedBySlug($slug);

        return view('public.pages.show', [
            'page' => $page,
            'jsonLd' => [
                SchemaOrg::page($page),
                SchemaOrg::breadcrumbs([['Home', route('home')], [$page->title, $page->url()]]),
            ],
        ]);
    }
}
