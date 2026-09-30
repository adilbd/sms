<?php

namespace App\Http\Requests\Shift;

use Illuminate\Foundation\Http\FormRequest;

class IndexShiftRequest extends FormRequest
{
    // Access is enforced by the permission middleware in ShiftController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'sometimes|nullable|string|max:100',
            'is_active' => 'sometimes|nullable|in:true,false,1,0',
        ];
    }
}
