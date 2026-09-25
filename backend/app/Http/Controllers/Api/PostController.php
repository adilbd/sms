<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Post\IndexPostRequest;
use App\Http\Requests\Post\StorePostRequest;
use App\Http\Requests\Post\UpdatePostRequest;
use App\Http\Resources\PostResource;
use App\Models\Post;
use App\Services\PostService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class PostController extends Controller implements HasMiddleware
{
    public function __construct(private PostService $posts) {}

    public static function middleware(): array
    {
        return [new Middleware('role:admin')];
    }

    public function index(IndexPostRequest $request)
    {
        $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

        return PostResource::collection(
            $this->posts->list($request->safe()->only(['type', 'search', 'is_published']), $perPage)
        );
    }

    public function store(StorePostRequest $request)
    {
        $post = $this->posts->create($request->validated(), $request->user());

        return (new PostResource($post))
            ->withBody()
            ->additional(['message' => 'Post created successfully'])
            ->response()
            ->setStatusCode(201);
    }

    public function show(Post $post)
    {
        return (new PostResource($post))->withBody();
    }

    public function update(UpdatePostRequest $request, Post $post)
    {
        $post = $this->posts->update($post, $request->validated());

        return (new PostResource($post))->withBody()->additional(['message' => 'Post updated successfully']);
    }

    public function destroy(Post $post)
    {
        $this->posts->delete($post);

        return response()->noContent();
    }
}
