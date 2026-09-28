<?php

namespace App\Http\Requests\MenuItem;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReorderMenuItemsRequest extends FormRequest
{
    // Access is enforced by the role:admin middleware in MenuItemController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1',
            'items.*.id' => ['required', 'integer', Rule::exists('menu_items', 'id')],
            'items.*.parent_id' => ['present', 'nullable', 'integer', Rule::exists('menu_items', 'id')],
            'items.*.sort_order' => 'required|integer|min:0',
        ];
    }
}
