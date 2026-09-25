<?php

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

class UploadPostMediaRequest extends FormRequest
{
    // Access is enforced by the role:admin middleware in PostMediaController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // SVG is excluded: it can carry script content.
            'file' => 'required|file|image|mimes:jpg,jpeg,png,webp,gif|max:5120',
        ];
    }
}
