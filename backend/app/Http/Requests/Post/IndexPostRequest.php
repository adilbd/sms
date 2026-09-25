<?php

namespace App\Http\Requests\Post;

use App\Models\Post;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexPostRequest extends FormRequest
{
    // Access is enforced by the role:admin middleware in PostController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'nullable', Rule::in(Post::TYPES)],
            'search' => 'sometimes|nullable|string|max:255',
            // Query strings arrive as text, so accept the common boolean spellings.
            'is_published' => 'sometimes|nullable|in:true,false,1,0',
        ];
    }
}
