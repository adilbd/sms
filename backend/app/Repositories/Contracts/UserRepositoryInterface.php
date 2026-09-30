<?php

namespace App\Repositories\Contracts;

use App\Models\User;

interface UserRepositoryInterface extends RepositoryInterface
{
    /**
     * The user whose email, username or (normalized) mobile number equals $login. A
     * student signs in with their student ID and a guardian with their mobile number,
     * both stored as `username`.
     */
    public function findForLogin(string $login): ?User;

    public function findByUsername(string $username): ?User;

    /**
     * Creates the user and assigns $role in one call.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function createWithRole(array $attributes, string $role): User;

    public function hasRole(User $user, string $role): bool;

    /**
     * Issues a Sanctum bearer token and returns its plain-text value.
     */
    public function issueToken(User $user, string $name): string;

    /**
     * Deletes the token the current request was authenticated with (a no-op for
     * sessions that have none, such as tests using actingAs()).
     */
    public function revokeCurrentToken(User $user): void;

    public function revokeAllTokens(User $user): void;
}
