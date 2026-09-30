<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in for every role: an admin or teacher uses an email, a student their student ID
 * and a guardian their mobile number (see UserRepositoryInterface::findForLogin()).
 */
class AuthService
{
    public function __construct(private UserRepositoryInterface $users) {}

    /**
     * @return array{user: User, token: string}
     */
    public function login(string $login, string $password, ?string $deviceName): array
    {
        $user = $this->users->findForLogin($login);

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'login' => ['Your account has been deactivated.'],
            ]);
        }

        return [
            'user' => $user,
            'token' => $this->users->issueToken($user, $deviceName ?: 'auth-token'),
        ];
    }

    public function logout(User $user): void
    {
        $this->users->revokeCurrentToken($user);
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The provided password is incorrect.'],
            ]);
        }

        $this->users->update($user, ['password' => $newPassword]);

        // Other devices must sign in again with the new password; this one stays signed in.
        $this->users->revokeOtherTokens($user);
    }
}
