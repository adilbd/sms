<?php

namespace App\Http\Requests\Post;

use App\Http\Requests\Post\Concerns\NormalizesPostSlug;
use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostRequest extends FormRequest
{
    use NormalizesPostSlug;

    // Access is enforced by the role:admin middleware in PostController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in(Post::TYPES)],
            'title' => 'sometimes|string|max:255',
            'slug' => ['nullable', 'alpha_dash:ascii', 'max:255', Rule::unique('posts', 'slug')->ignore($this->route('post'))],
            'excerpt' => 'nullable|string|max:500',
            'body' => 'sometimes|string|max:200000',
            'cover_image' => 'nullable|string|max:255',
            // Whether an event requires event_starts_at, and event_ends_at must not be
            // before it, depends on saved state (a partial update might send only one of
            // the two, or neither): PostService::update checks that against the model.
            'event_starts_at' => 'nullable|date',
            'event_ends_at' => 'nullable|date',
            'location' => 'nullable|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:300',
            'is_published' => 'sometimes|boolean',
            'published_at' => 'nullable|date',
        ];
    }
}
