<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Gallery\IndexGalleryRequest;
use App\Http\Requests\Gallery\StoreGalleryRequest;
use App\Http\Requests\Gallery\UpdateGalleryRequest;
use App\Http\Resources\GalleryResource;
use App\Models\Gallery;
use App\Services\GalleryService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class GalleryController extends Controller implements HasMiddleware
{
    public function __construct(private GalleryService $galleries) {}

    public static function middleware(): array
    {
        // Same as pages, posts and menu: role:admin for every action.
        return [new Middleware('role:admin')];
    }

    public function index(IndexGalleryRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return GalleryResource::collection(
            $this->galleries->list($request->safe()->only(['search', 'is_published']), $perPage)
        );
    }

    public function store(StoreGalleryRequest $request)
    {
        $gallery = $this->galleries->create($request->validated());

        return (new GalleryResource($gallery))
            ->withItems()
            ->additional(['message' => 'Gallery created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Gallery $gallery)
    {
        return (new GalleryResource($this->galleries->find($gallery)))->withItems();
    }

    public function update(UpdateGalleryRequest $request, Gallery $gallery)
    {
        $gallery = $this->galleries->update($gallery, $request->validated());

        return (new GalleryResource($gallery))->withItems()->additional(['message' => 'Gallery updated successfully']);
    }

    public function destroy(Gallery $gallery)
    {
        $this->galleries->delete($gallery);

        return response()->noContent();
    }
}
