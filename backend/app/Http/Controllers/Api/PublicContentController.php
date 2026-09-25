<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Services\ContactService;
use App\Services\PostService;
use Illuminate\Http\Request;

/**
 * Unauthenticated, read-mostly endpoints for the mobile app.
 * Uses the same PostService as the Blade site, so both show identical content.
 */
class PublicContentController extends Controller
{
    public function __construct(private PostService $posts)
    {
    }

    public function school()
    {
        $org = config('seo.organization');

        return response()->json(['data' => [
            'name' => config('seo.site_name'),
            'description' => config('seo.default_description'),
            'email' => $org['email'],
            'phone' => $org['phone'],
            'address' => [
                'street' => $org['street'],
                'city' => $org['city'],
                'region' => $org['region'],
                'postal_code' => $org['postal_code'],
                'country' => $org['country'],
            ],
            'social' => $org['social'],
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
