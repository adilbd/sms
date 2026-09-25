<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PostController extends Controller
{
    public function index(Request $request)
    {
        $query = Post::query()->latest();

        if ($request->filled('type')) {
            $query->ofType($request->type);
        }

        if ($request->filled('search')) {
            $query->where('title', 'like', "%{$request->search}%");
        }

        return $query->paginate($request->per_page ?? 15);
    }

    public function store(Request $request)
    {
        $post = Post::create([
            ...$this->validated($request),
            'author_id' => $request->user()->id,
        ]);

        return response()->json($post, 201);
    }

    public function show(Post $post)
    {
        return response()->json($post);
    }

    public function update(Request $request, Post $post)
    {
        $post->update($this->validated($request, $post));

        return response()->json($post);
    }

    public function destroy(Post $post)
    {
        $post->delete();

        return response()->json(['message' => 'Post deleted successfully']);
    }

    private function validated(Request $request, ?Post $post = null): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(Post::TYPES)],
            'title' => 'required|string|max:255',
            'slug' => ['nullable', 'alpha_dash', 'max:255', Rule::unique('posts', 'slug')->ignore($post?->id)],
            'excerpt' => 'nullable|string|max:500',
            'body' => 'required|string',
            'cover_image' => 'nullable|string|max:255',
            'event_starts_at' => 'nullable|required_if:type,event|date',
            'event_ends_at' => 'nullable|date|after_or_equal:event_starts_at',
            'location' => 'nullable|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:300',
            'is_published' => 'boolean',
            'published_at' => 'nullable|date',
        ]);
    }
}
