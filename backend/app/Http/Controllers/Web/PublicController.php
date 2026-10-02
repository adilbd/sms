<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\InstituteSettingsService;
use App\Services\PostService;
use App\Support\SchemaOrg;

class PublicController extends Controller
{
    public function __construct(private PostService $posts, private InstituteSettingsService $institute) {}

    public function home()
    {
        $institute = $this->institute->profile();

        return view('public.home', [
            'latestNews' => $this->posts->latestNews(),
            'upcomingEvents' => $this->posts->upcomingEvents(),
            'jsonLd' => [SchemaOrg::organization($institute), SchemaOrg::website($institute)],
        ]);
    }

    public function about()
    {
        return view('public.about', [
            'jsonLd' => [$this->breadcrumbs('About Us', route('about'))],
        ]);
    }

    private function breadcrumbs(string $name, string $url): array
    {
        return SchemaOrg::breadcrumbs([['Home', route('home')], [$name, $url]]);
    }
}
