<?php

namespace App\Http\Requests\Post;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePostRequest extends FormRequest
{
    // Access is enforced by the role:admin middleware in PostController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(Post::TYPES)],
            'title' => 'required|string|max:255',
            'slug' => ['nullable', 'alpha_dash', 'max:255', Rule::unique('posts', 'slug')],
            'excerpt' => 'nullable|string|max:500',
            'body' => 'required|string|max:200000',
            'cover_image' => 'nullable|string|max:255',
            'event_starts_at' => 'nullable|required_if:type,event|date',
            'event_ends_at' => 'nullable|date|after_or_equal:event_starts_at',
            'location' => 'nullable|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:300',
            'is_published' => 'sometimes|boolean',
            'published_at' => 'nullable|date',
        ];
    }
}
