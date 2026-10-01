<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * IPs a user has signed in from before, which skip the per-account failure lock (see
 * AuthService::login()). Each user has a trust version that is part of the key, so bumping
 * it ({@see self::invalidate()}) drops every trusted IP of that user at once. Call it
 * whenever the credentials change or the login is switched off: a password change or reset,
 * a deactivation, or any other place tokens are revoked.
 */
class LoginTrust
{
    private const DAYS = 30;

    public static function trust(User $user, string $ip): void
    {
        Cache::put(self::key($user, $ip), true, now()->addDays(self::DAYS));
    }

    public static function isTrusted(User $user, string $ip): bool
    {
        return Cache::has(self::key($user, $ip));
    }

    public static function invalidate(User $user): void
    {
        Cache::forever(self::versionKey($user), self::version($user) + 1);
    }

    public static function key(User $user, string $ip): string
    {
        return 'login-trusted:'.$user->id.':'.self::version($user).':'.sha1($ip);
    }

    private static function version(User $user): int
    {
        return (int) Cache::get(self::versionKey($user), 0);
    }

    private static function versionKey(User $user): string
    {
        return 'login-trust-version:'.$user->id;
    }
}
