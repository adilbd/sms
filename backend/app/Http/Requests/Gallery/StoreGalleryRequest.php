<?php

namespace App\Http\Requests\Gallery;

use App\Http\Requests\Gallery\Concerns\HasGalleryItemRules;
use App\Http\Requests\Gallery\Concerns\NormalizesGallerySlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGalleryRequest extends FormRequest
{
    use HasGalleryItemRules, NormalizesGallerySlug;

    // Access is enforced by the role:admin middleware in GalleryController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge([
            'title' => 'required|string|max:255',
            'slug' => ['nullable', 'alpha_dash:ascii', 'max:255', Rule::unique('galleries', 'slug')],
            'description' => 'nullable|string|max:2000',
            'cover_media_id' => ['nullable', 'integer', Rule::exists('media', 'id')],
            'is_published' => 'sometimes|boolean',
            'published_at' => 'nullable|date',
            'sort_order' => 'sometimes|integer|min:0|max:65535',
        ], $this->itemRules());
    }
}
