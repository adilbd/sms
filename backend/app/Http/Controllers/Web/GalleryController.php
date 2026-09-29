<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\GalleryService;
use App\Support\SchemaOrg;

class GalleryController extends Controller
{
    public function __construct(private GalleryService $galleries) {}

    public function index()
    {
        $galleries = $this->galleries->paginatePublished();

        // Out-of-range pages are not real content.
        abort_if($galleries->currentPage() > 1 && $galleries->isEmpty(), 404);

        return view('public.galleries.index', [
            'galleries' => $galleries,
            'jsonLd' => [SchemaOrg::breadcrumbs([['Home', route('home')], ['Gallery', route('gallery.index')]])],
        ]);
    }

    public function show(string $slug)
    {
        $gallery = $this->galleries->findPublishedBySlug($slug);

        return view('public.galleries.show', [
            'gallery' => $gallery,
            'jsonLd' => [
                SchemaOrg::breadcrumbs([
                    ['Home', route('home')],
                    ['Gallery', route('gallery.index')],
                    [$gallery->title, $gallery->url()],
                ]),
            ],
        ]);
    }
}
