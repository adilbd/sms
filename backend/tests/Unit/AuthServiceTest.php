<?php

namespace Tests\Unit;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\AuthService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface — no database.
 */
class AuthServiceTest extends TestCase
{
    private function user(bool $active = true): User
    {
        $user = new User(['name' => 'Rahim', 'password' => 'secret-pass', 'is_active' => $active]);
        $user->id = 7;

        return $user;
    }

    public function test_login_returns_the_user_and_a_token(): void
    {
        $user = $this->user();

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('findForLogin')->once()->with('20260001')->andReturn($user);
            $mock->shouldReceive('issueToken')->once()->with($user, 'iPhone')->andReturn('plain-token');
        });

        $result = app(AuthService::class)->login('20260001', 'secret-pass', 'iPhone');

        $this->assertSame($user, $result['user']);
        $this->assertSame('plain-token', $result['token']);
    }

    public function test_login_uses_a_default_token_name(): void
    {
        $user = $this->user();

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('findForLogin')->andReturn($user);
            $mock->shouldReceive('issueToken')->once()->with($user, 'auth-token')->andReturn('t');
        });

        app(AuthService::class)->login('x', 'secret-pass', null);
    }

    public function test_login_rejects_an_unknown_login(): void
    {
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('findForLogin')->once()->andReturn(null);
            $mock->shouldNotReceive('issueToken');
        });

        try {
            app(AuthService::class)->login('nobody', 'secret-pass', null);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('login', $e->errors());
        }
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('findForLogin')->once()->andReturn($this->user());
            $mock->shouldNotReceive('issueToken');
        });

        $this->expectException(ValidationException::class);

        app(AuthService::class)->login('x', 'wrong', null);
    }

    public function test_login_rejects_an_inactive_account_only_after_the_password_matches(): void
    {
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('findForLogin')->twice()->andReturn($this->user(active: false));
            $mock->shouldNotReceive('issueToken');
        });

        try {
            app(AuthService::class)->login('x', 'secret-pass', null);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['Your account has been deactivated.'], $e->errors()['login']);
        }

        // A wrong password on a deactivated account doesn't reveal that it is deactivated.
        try {
            app(AuthService::class)->login('x', 'wrong', null);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['The provided credentials are incorrect.'], $e->errors()['login']);
        }
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = $this->user();

        $this->mock(UserRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldReceive('revokeCurrentToken')->once()->with($user));

        app(AuthService::class)->logout($user);
    }

    public function test_change_password_updates_the_password(): void
    {
        $user = $this->user();

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('update')->once()->with($user, ['password' => 'new-password-1']);
        });

        app(AuthService::class)->changePassword($user, 'secret-pass', 'new-password-1');
    }

    public function test_change_password_rejects_a_wrong_current_password(): void
    {
        $user = $this->user();

        $this->mock(UserRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('update'));

        try {
            app(AuthService::class)->changePassword($user, 'wrong', 'new-password-1');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('current_password', $e->errors());
        }

        $this->assertTrue(Hash::check('secret-pass', $user->password));
    }
}
