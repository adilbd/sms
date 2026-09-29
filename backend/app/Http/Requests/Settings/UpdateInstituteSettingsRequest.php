<?php

namespace App\Http\Requests\Settings;

use App\Support\InstituteSettings;
use Illuminate\Foundation\Http\FormRequest;

class UpdateInstituteSettingsRequest extends FormRequest
{
    // Access is enforced by the permission middleware in InstituteSettingsController.
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(InstituteSettings::rules(), [
            // SVG is rejected (not in the mimes list) because it can carry XSS.
            'logo' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'favicon' => ['sometimes', 'nullable', 'mimes:png,ico', 'max:512'],
            'remove_logo' => ['sometimes', 'boolean'],
            'remove_favicon' => ['sometimes', 'boolean'],
        ]);
    }
}
