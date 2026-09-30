<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\Mobile;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class UserRepository extends EloquentRepository implements UserRepositoryInterface
{
    protected string $model = User::class;

    public function findForLogin(string $login): ?User
    {
        $candidates = array_values(array_unique(array_filter([trim($login), Mobile::normalize($login)])));

        if ($candidates === []) {
            return null;
        }

        $lower = array_map('mb_strtolower', $candidates);

        // Email compares case-insensitively on every database; usernames are digits.
        return User::query()
            ->where(fn ($q) => $q
                ->whereIn(DB::raw('lower(email)'), $lower)
                ->orWhereIn('username', $candidates))
            ->orderBy('id')
            ->first();
    }

    public function findByUsername(string $username): ?User
    {
        return User::query()->where('username', $username)->first();
    }

    public function createWithRole(array $attributes, string $role): User
    {
        $user = User::create($attributes);
        $user->assignRole($role);

        return $user;
    }

    public function hasRole(User $user, string $role): bool
    {
        return $user->hasRole($role);
    }

    public function issueToken(User $user, string $name): string
    {
        return $user->createToken($name)->plainTextToken;
    }

    public function revokeCurrentToken(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }

    public function revokeAllTokens(User $user): void
    {
        $user->tokens()->delete();
    }
}
