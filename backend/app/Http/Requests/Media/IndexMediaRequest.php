<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;

class IndexMediaRequest extends FormRequest
{
    // Access is enforced by the role:admin middleware in MediaController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'sometimes|nullable|string|max:255',
        ];
    }
}
