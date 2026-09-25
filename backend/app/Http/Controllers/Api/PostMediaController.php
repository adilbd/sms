<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Post\UploadPostMediaRequest;
use App\Services\PostMediaService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Storage;

class PostMediaController extends Controller implements HasMiddleware
{
    public function __construct(private PostMediaService $media) {}

    public static function middleware(): array
    {
        return [new Middleware('role:admin')];
    }

    public function store(UploadPostMediaRequest $request)
    {
        $path = $this->media->storeImage($request->file('file'));

        return response()->json([
            'data' => [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
            ],
        ], 201);
    }
}
