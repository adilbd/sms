<?php

namespace App\Http\Requests\Class;

use App\Http\Requests\Class\Concerns\NormalizesClassCode;
use App\Models\Classes;
use Illuminate\Foundation\Http\FormRequest;

class StoreClassRequest extends FormRequest
{
    use NormalizesClassCode;

    // Access is enforced by the permission middleware in ClassController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'number' => [
                'required', 'integer',
                'min:'.Classes::MIN_NUMBER, 'max:'.Classes::MAX_NUMBER,
                'unique:classes,number',
            ],
            'name' => 'required|string|max:255',
            'name_bn' => 'nullable|string|max:255',
            'code' => 'required|string|max:255|unique:classes,code',
            'description' => 'nullable|string',
            'display_order' => 'sometimes|integer',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
