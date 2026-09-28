<?php

namespace App\Http\Requests\MenuItem;

use App\Models\MenuItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexMenuItemRequest extends FormRequest
{
    // Access is enforced by the role:admin middleware in MenuItemController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location' => ['sometimes', 'nullable', Rule::in(MenuItem::LOCATIONS)],
            'is_active' => 'sometimes|nullable|in:true,false,1,0',
            'parent_id' => 'sometimes|nullable|integer',
        ];
    }
}
