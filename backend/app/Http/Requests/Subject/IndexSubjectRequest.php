<?php

namespace App\Http\Requests\Subject;

use Illuminate\Foundation\Http\FormRequest;

class IndexSubjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'sometimes|nullable|string|max:100',
            // Query strings arrive as text, so accept the common boolean spellings.
            'is_active' => 'sometimes|nullable|in:true,false,1,0',
        ];
    }
}
