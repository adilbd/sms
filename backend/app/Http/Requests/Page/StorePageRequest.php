<?php

namespace App\Http\Requests\Page;

use App\Http\Requests\Page\Concerns\NormalizesPageSlug;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePageRequest extends FormRequest
{
    use NormalizesPageSlug;

    // Access is enforced by the role:admin middleware in PageController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'slug' => ['nullable', 'alpha_dash:ascii', 'max:255', Rule::unique('pages', 'slug')],
            'body' => 'required|string|max:200000',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:300',
            'is_published' => 'sometimes|boolean',
            'published_at' => 'nullable|date',
        ];
    }
}
