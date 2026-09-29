<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class StoreMediaRequest extends FormRequest
{
    // Access is enforced by the role:admin middleware in MediaController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // SVG is excluded: it can carry script content (same as post-body uploads).
            'file' => 'required|file|image|mimes:jpg,jpeg,png,webp,gif|max:5120',
        ];
    }
}
