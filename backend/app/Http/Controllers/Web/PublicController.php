<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\PostService;
use App\Support\SchemaOrg;

class PublicController extends Controller
{
    public function __construct(private PostService $posts)
    {
    }

    public function home()
    {
        return view('public.home', [
            'latestNews' => $this->posts->latestNews(),
            'upcomingEvents' => $this->posts->upcomingEvents(),
            'jsonLd' => [SchemaOrg::organization(), SchemaOrg::website()],
        ]);
    }

    public function about()
    {
        return view('public.about', [
            'jsonLd' => [$this->breadcrumbs('About Us', route('about'))],
        ]);
    }

    public function admissions()
    {
        return view('public.admissions', [
            'jsonLd' => [$this->breadcrumbs('Admissions', route('admissions'))],
        ]);
    }

    private function breadcrumbs(string $name, string $url): array
    {
        return SchemaOrg::breadcrumbs([['Home', route('home')], [$name, $url]]);
    }
}
