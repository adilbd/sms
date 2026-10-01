<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Result\LookupResultRequest;
use App\Http\Resources\ExamResource;
use App\Http\Resources\ExamResultResource;
use App\Http\Resources\GalleryResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\StaffResource;
use App\Models\Post;
use App\Models\Staff;
use App\Services\ContactService;
use App\Services\GalleryService;
use App\Services\InstituteSettingsService;
use App\Services\PostService;
use App\Services\ResultService;
use App\Services\StaffService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Unauthenticated, read-mostly endpoints for the mobile app.
 * Uses the same PostService/GalleryService/StaffService as the Blade site, so both
 * show identical content.
 */
class PublicContentController extends Controller
{
    public function __construct(
        private PostService $posts,
        private InstituteSettingsService $institute,
        private GalleryService $galleries,
        private StaffService $staff,
        private ResultService $results,
    ) {}

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

    public function staff(Request $request)
    {
        $validated = $request->validate([
            // An array query value (?shift[]=x) would otherwise reach
            // StaffService::resolveActiveShift(), which type-hints string, as a 500.
            'position' => ['sometimes', 'nullable', 'string', Rule::in(Staff::POSITIONS)],
            'former' => ['sometimes', 'nullable', 'boolean'],
            'shift' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $filters = [
            'position' => $validated['position'] ?? null,
            'shift' => $validated['shift'] ?? null,
        ];

        if ($request->has('former')) {
            $filters['former'] = $request->boolean('former');
        }

        // StaffResource::collection() has no hook to flag every item public() up front,
        // but ResourceCollection::collectResource() already maps every item into a
        // StaffResource before this method returns, so mutating each one in place still
        // produces the normal, auto-wrapped {"data": [...]} response.
        return tap(
            StaffResource::collection($this->staff->publicList($filters)),
            fn ($collection) => $collection->collection->each->public(),
        );
    }

    public function staffShow(int $staff)
    {
        return (new StaffResource($this->staff->publicFind($staff)))->public();
    }

    /**
     * Published exams with their classes and sections, for the result lookup's selects.
     * Same data as the website's /results form.
     */
    public function exams()
    {
        return ExamResource::collection($this->results->publishedExams());
    }

    public function results(LookupResultRequest $request)
    {
        $result = $this->results->publicLookup($request->validated(), (string) $request->ip());

        return (new ExamResultResource($result))->withSubjects();
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
