<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    // Public endpoint: anyone may try to sign in.
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `login` takes an email, a student ID or a mobile number. `email` is still accepted
     * as an alias so older clients keep working.
     */
    protected function prepareForValidation(): void
    {
        if (! $this->filled('login') && $this->filled('email')) {
            $this->merge(['login' => $this->input('email')]);
        }
    }

    public function rules(): array
    {
        return [
            'login' => 'required|string|max:255',
            'password' => 'required|string',
            'device_name' => 'nullable|string|max:255',
        ];
    }
}
