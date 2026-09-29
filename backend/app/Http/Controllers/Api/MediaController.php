<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\IndexMediaRequest;
use App\Http\Requests\Media\StoreMediaRequest;
use App\Http\Requests\Media\UpdateMediaRequest;
use App\Http\Resources\MediaResource;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class MediaController extends Controller implements HasMiddleware
{
    public function __construct(private MediaService $media) {}

    public static function middleware(): array
    {
        // Same as pages, posts and menu: role:admin for every action.
        return [new Middleware('role:admin')];
    }

    public function index(IndexMediaRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 24), 1), 100);

        return MediaResource::collection(
            $this->media->list($request->safe()->only(['search']), $perPage)
        );
    }

    public function store(StoreMediaRequest $request)
    {
        $media = $this->media->upload($request->file('file'));

        return (new MediaResource($media))
            ->additional(['message' => 'Image uploaded successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateMediaRequest $request, Media $media)
    {
        $media = $this->media->update($media, $request->validated());

        return (new MediaResource($media))->additional(['message' => 'Image updated successfully']);
    }

    public function destroy(Media $media)
    {
        $this->media->delete($media);

        return response()->noContent();
    }
}
