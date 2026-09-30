<?php

namespace App\Http\Requests\Class;

use App\Http\Requests\Class\Concerns\NormalizesClassCode;
use App\Models\Classes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClassRequest extends FormRequest
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
                'sometimes', 'integer',
                'min:'.Classes::MIN_NUMBER, 'max:'.Classes::MAX_NUMBER,
                Rule::unique('classes', 'number')->ignore($this->route('class')),
            ],
            'name' => 'sometimes|string|max:255',
            'name_bn' => 'sometimes|nullable|string|max:255',
            'code' => ['sometimes', 'string', 'max:255', Rule::unique('classes', 'code')->ignore($this->route('class'))],
            'description' => 'nullable|string',
            'display_order' => 'sometimes|integer',
            'is_active' => 'sometimes|boolean',
        ];
    }
}
