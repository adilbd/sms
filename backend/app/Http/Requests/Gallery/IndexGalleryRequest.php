<?php

namespace App\Http\Requests\Gallery;

use Illuminate\Foundation\Http\FormRequest;

class IndexGalleryRequest extends FormRequest
{
    // Access is enforced by the role:admin middleware in GalleryController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'sometimes|nullable|string|max:255',
            // Query strings arrive as text, so accept the common boolean spellings.
            'is_published' => 'sometimes|nullable|in:true,false,1,0',
        ];
    }
}
