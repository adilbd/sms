<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in for every role: an admin or teacher uses an email, a student their student ID
 * and a guardian their mobile number (see UserRepositoryInterface::findForLogin()).
 */
class AuthService
{
    /** Failed passwords a minute per account, from IPs that haven't signed in as it before. */
    private const MAX_ACCOUNT_FAILURES = 10;

    /** Failed passwords a minute per IP, across every identifier. Successes never count. */
    private const MAX_IP_FAILURES = 30;

    private const TRUST_DAYS = 30;

    public function __construct(private UserRepositoryInterface $users) {}

    /**
     * @return array{user: User, token: string}
     */
    public function login(string $login, string $password, ?string $deviceName, string $ip): array
    {
        $ipKey = 'login-ip-fail:'.sha1($ip);

        // Counts failures only, so a school behind one public IP isn't throttled by its own
        // successful sign-ins. Once tripped it applies to everyone on that IP.
        $this->ensureNotLocked($ipKey, self::MAX_IP_FAILURES);

        $user = $this->users->findForLogin($login);
        $key = $user ? 'login-user:'.$user->id : null;
        $trustKey = $user ? self::trustKey($user, $ip) : null;

        // Failures are also counted per resolved account, so spelling variants of one login
        // (which the route limiter sees as different identifiers) and many IPs share a
        // single bucket. An IP that has signed in as this user before skips the lock, so an
        // attacker hammering the account from elsewhere can't keep its owner out.
        if ($key && ! Cache::has($trustKey)) {
            $this->ensureNotLocked($key, self::MAX_ACCOUNT_FAILURES);
        }

        if (! $user || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($ipKey, 60);

            if ($key) {
                RateLimiter::hit($key, 60);
            }

            throw ValidationException::withMessages([
                'login' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'login' => ['Your account has been deactivated.'],
            ]);
        }

        RateLimiter::clear($key);
        Cache::put($trustKey, true, now()->addDays(self::TRUST_DAYS));

        return [
            'user' => $user,
            'token' => $this->users->issueToken($user, $deviceName ?: 'auth-token'),
        ];
    }

    private static function trustKey(User $user, string $ip): string
    {
        return 'login-trusted:'.$user->id.':'.sha1($ip);
    }

    private function ensureNotLocked(string $key, int $max): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw new ThrottleRequestsException(
                'Too many login attempts. Please try again later.',
                null,
                ['Retry-After' => (string) RateLimiter::availableIn($key)],
            );
        }
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
