<?php

namespace App\Http\Requests\MenuItem;

use App\Models\MenuItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMenuItemRequest extends FormRequest
{
    // Access is enforced by the role:admin middleware in MenuItemController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location' => ['sometimes', Rule::in(MenuItem::LOCATIONS)],
            'parent_id' => ['nullable', 'integer', Rule::exists('menu_items', 'id')],
            'label' => 'required|string|max:255',
            'type' => ['required', Rule::in(MenuItem::TYPES)],
            'page_id' => ['nullable', 'integer', Rule::exists('pages', 'id')],
            'route_name' => ['nullable', Rule::in(MenuItem::ROUTES)],
            'url' => ['nullable', 'string', 'max:2048', 'regex:#^(/|https?://)#'],
            'sort_order' => 'sometimes|integer|min:0',
            'is_active' => 'sometimes|boolean',
            'open_in_new_tab' => 'sometimes|boolean',
        ];
    }
}
