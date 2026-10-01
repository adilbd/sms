<?php

namespace Tests\Unit;

use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\AuthService;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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

        $result = app(AuthService::class)->login('20260001', 'secret-pass', 'iPhone', '10.0.0.1');

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

        app(AuthService::class)->login('x', 'secret-pass', null, '10.0.0.1');
    }

    public function test_login_rejects_an_unknown_login(): void
    {
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('findForLogin')->once()->andReturn(null);
            $mock->shouldNotReceive('issueToken');
        });

        try {
            app(AuthService::class)->login('nobody', 'secret-pass', null, '10.0.0.1');
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

        app(AuthService::class)->login('x', 'wrong', null, '10.0.0.1');
    }

    public function test_login_rejects_an_inactive_account_only_after_the_password_matches(): void
    {
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('findForLogin')->twice()->andReturn($this->user(active: false));
            $mock->shouldNotReceive('issueToken');
        });

        try {
            app(AuthService::class)->login('x', 'secret-pass', null, '10.0.0.1');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['Your account has been deactivated.'], $e->errors()['login']);
        }

        // A wrong password on a deactivated account doesn't reveal that it is deactivated.
        try {
            app(AuthService::class)->login('x', 'wrong', null, '10.0.0.1');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['The provided credentials are incorrect.'], $e->errors()['login']);
        }
    }

    private function failAs(string $ip, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            try {
                app(AuthService::class)->login('x', 'wrong', null, $ip);
            } catch (ValidationException) {
            }
        }
    }

    public function test_a_locked_account_rejects_with_retry_after_even_for_the_right_password(): void
    {
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('findForLogin')->andReturn($this->user());
            $mock->shouldNotReceive('issueToken');
        });

        foreach (range(1, 10) as $n) {
            $this->failAs("10.0.1.{$n}", 1);
        }

        try {
            app(AuthService::class)->login('x', 'secret-pass', null, '10.0.2.1');
            $this->fail('Expected a ThrottleRequestsException.');
        } catch (ThrottleRequestsException $e) {
            $this->assertGreaterThan(0, (int) $e->getHeaders()['Retry-After']);
        }
    }

    public function test_a_trusted_ip_skips_the_account_lock_and_untrusted_ones_do_not(): void
    {
        $user = $this->user();

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($user) {
            $mock->shouldReceive('findForLogin')->andReturn($user);
            $mock->shouldReceive('issueToken')->once()->andReturn('t');
        });

        Cache::put('login-trusted:7:0:'.sha1('10.0.0.5'), true, now()->addDay());
        RateLimiter::increment('login-user:7', 60, 10);

        $this->assertSame('t', app(AuthService::class)->login('x', 'secret-pass', null, '10.0.0.5')['token']);

        // A success clears the account's counter, so lock it again for the untrusted IP.
        RateLimiter::increment('login-user:7', 60, 10);

        $this->expectException(ThrottleRequestsException::class);
        app(AuthService::class)->login('x', 'secret-pass', null, '10.0.0.6');
    }

    public function test_a_successful_login_trusts_the_ip_and_does_not_count_toward_the_ip_limit(): void
    {
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('findForLogin')->andReturn($this->user());
            $mock->shouldReceive('issueToken')->andReturn('t');
        });

        for ($i = 0; $i < 40; $i++) {
            app(AuthService::class)->login('x', 'secret-pass', null, '10.0.0.7');
        }

        $this->assertTrue(Cache::has('login-trusted:7:0:'.sha1('10.0.0.7')));
        $this->assertSame(0, RateLimiter::attempts('login-ip-fail:'.sha1('10.0.0.7')));
    }

    public function test_only_failures_count_toward_the_ip_limit_and_it_blocks_everyone_on_that_ip(): void
    {
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('findForLogin')->with('nobody')->andReturn(null);
            $mock->shouldReceive('findForLogin')->with('x')->andReturn($this->user());
            $mock->shouldNotReceive('issueToken');
        });

        for ($i = 0; $i < 30; $i++) {
            try {
                app(AuthService::class)->login('nobody', 'x', null, '10.0.0.8');
            } catch (ValidationException) {
            }
        }

        try {
            app(AuthService::class)->login('x', 'secret-pass', null, '10.0.0.8');
            $this->fail('Expected a ThrottleRequestsException.');
        } catch (ThrottleRequestsException $e) {
            $this->assertGreaterThan(0, (int) $e->getHeaders()['Retry-After']);
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
            $mock->shouldReceive('revokeOtherTokens')->once()->with($user);
        });

        app(AuthService::class)->changePassword($user, 'secret-pass', 'new-password-1');
    }

    public function test_change_password_rejects_a_wrong_current_password(): void
    {
        $user = $this->user();

        $this->mock(UserRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('update', 'revokeOtherTokens'));

        try {
            app(AuthService::class)->changePassword($user, 'wrong', 'new-password-1');
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('current_password', $e->errors());
        }

        $this->assertTrue(Hash::check('secret-pass', $user->password));
    }
}
