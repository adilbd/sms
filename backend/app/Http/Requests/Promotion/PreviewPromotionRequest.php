<?php

namespace App\Http\Requests\Promotion;

use Illuminate\Foundation\Http\FormRequest;

class PreviewPromotionRequest extends FormRequest
{
    // Access is enforced by the permission middleware in PromotionController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_academic_year_id' => 'required|integer|exists:academic_years,id',
            'section_id' => 'required|integer|exists:sections,id',
            'to_academic_year_id' => 'sometimes|nullable|integer|exists:academic_years,id',
            'class_id' => 'sometimes|nullable|integer|exists:classes,id',
        ];
    }
}
