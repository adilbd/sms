<?php

namespace App\Http\Requests\Staff\Concerns;

use App\Models\User;
use App\Support\Username;
use Illuminate\Validation\Rule;

/**
 * Validation rules for the optional "login" block carried by the staff store/update
 * payload: {enabled, role, password?, email?}. Which role fits the category, whether an
 * employee ID exists and whether the username/email are free depend on saved state, so
 * StaffService checks those (see StaffService::syncLogin()). Shared by Store/UpdateStaffRequest.
 */
trait HasStaffLoginRules
{
    protected function loginRules(): array
    {
        return [
            'login' => 'sometimes|nullable|array',
            'login.enabled' => 'required_with:login|boolean',
            'login.role' => ['required_if:login.enabled,true,1', 'nullable', Rule::in(User::STAFF_LOGIN_ROLES)],
            'login.password' => 'sometimes|nullable|string|min:8|max:255',
            'login.email' => 'sometimes|nullable|email|max:255',
        ];
    }

    protected function normalizeLoginEmail(): void
    {
        $email = $this->input('login.email');

        if (is_string($email)) {
            $this->merge(['login' => array_merge($this->input('login', []), ['email' => Username::normalize($email)])]);
        }
    }
}
