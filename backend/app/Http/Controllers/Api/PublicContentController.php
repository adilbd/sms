<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GalleryResource;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Services\ContactService;
use App\Services\GalleryService;
use App\Services\InstituteSettingsService;
use App\Services\PostService;
use Illuminate\Http\Request;

/**
 * Unauthenticated, read-mostly endpoints for the mobile app.
 * Uses the same PostService/GalleryService as the Blade site, so both show identical
 * content.
 */
class PublicContentController extends Controller
{
    public function __construct(
        private PostService $posts,
        private InstituteSettingsService $institute,
        private GalleryService $galleries,
    ) {
    }

    public function school()
    {
        $institute = $this->institute->profile();

        return response()->json(['data' => [
            'name' => $institute['name_en'],
            'name_bn' => $institute['name_bn'],
            'description' => config('seo.default_description'),
            'email' => $institute['email'],
            'phone' => $institute['phone'],
            'telephone' => $institute['telephone'],
            'eiin' => $institute['eiin'],
            'institute_code' => $institute['institute_code'],
            'logo_url' => $institute['logo_url'],
            'favicon_url' => $institute['favicon_url'],
            'address' => [
                'street' => $institute['street'],
                'village' => $institute['village'],
                'ward' => $institute['ward'],
                'union' => $institute['union'],
                'post_office' => $institute['post_office'],
                'upazila' => $institute['upazila'],
                'city' => $institute['upazila'],
                'district' => $institute['district'],
                'region' => $institute['district'],
                'division' => $institute['division'],
                'postal_code' => $institute['post_code'],
                'country' => 'BD',
            ],
            'geo' => ($institute['latitude'] !== null && $institute['longitude'] !== null) ? [
                'latitude' => $institute['latitude'],
                'longitude' => $institute['longitude'],
            ] : null,
            'social' => $institute['social'],
            'web_url' => url('/'),
        ]]);
    }

    public function news(Request $request)
    {
        return PostResource::collection($this->posts->paginatePublished(Post::TYPE_NEWS, $this->perPage($request)));
    }

    public function newsShow(string $slug)
    {
        return (new PostResource($this->posts->findPublishedBySlug(Post::TYPE_NEWS, $slug)))->withBody();
    }

    public function events(Request $request)
    {
        return PostResource::collection($this->posts->paginatePublished(Post::TYPE_EVENT, $this->perPage($request)));
    }

    public function eventsShow(string $slug)
    {
        return (new PostResource($this->posts->findPublishedBySlug(Post::TYPE_EVENT, $slug)))->withBody();
    }

    public function galleries(Request $request)
    {
        return GalleryResource::collection($this->galleries->paginatePublished($this->perPage($request)));
    }

    public function galleriesShow(string $slug)
    {
        return (new GalleryResource($this->galleries->findPublishedBySlug($slug)))->withItems();
    }

    public function contact(Request $request, ContactService $contact)
    {
        $data = $request->validate(ContactService::RULES);

        $contact->submit($data, 'mobile', $request->ip());

        return response()->json(['message' => 'Thank you! We will get back to you soon.'], 201);
    }

    private function perPage(Request $request): int
    {
        return min(max((int) $request->query('per_page', 10), 1), 50);
    }
}
