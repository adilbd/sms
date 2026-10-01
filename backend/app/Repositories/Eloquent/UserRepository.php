<?php

namespace App\Repositories\Eloquent;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\Mobile;
use App\Support\Username;
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

        // Every writer stores emails lowercased (StudentService via the request's
        // prepareForValidation(), the seeders directly), so an exact match on the
        // lowercased input can use the unique index. Student IDs and mobile numbers are
        // digits; a staff member's username is their lowercased employee ID
        // (Username::normalize()), so the lowercased input is tried as a username too.
        $lower = array_map(fn (string $c) => Username::normalize($c), $candidates);

        return User::query()
            ->where(fn ($q) => $q
                ->whereIn('email', $lower)
                ->orWhereIn('username', array_values(array_unique([...$candidates, ...$lower]))))
            ->orderBy('id')
            ->first();
    }

    public function findByUsername(string $username): ?User
    {
        return User::query()->where('username', $username)->first();
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', Username::normalize($email))->first();
    }

    public function roleNames(User $user): array
    {
        return $user->getRoleNames()->values()->all();
    }

    public function syncRole(User $user, string $role): void
    {
        $user->syncRoles([$role]);
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

    public function revokeOtherTokens(User $user): void
    {
        $current = $user->currentAccessToken();

        $user->tokens()
            ->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->getKey()))
            ->delete();
    }
}
