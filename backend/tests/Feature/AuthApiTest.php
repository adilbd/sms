<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function studentLogin(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'email' => null, 'username' => '20260001', 'password' => 'student-pass',
        ], $attributes));
        $user->assignRole('student');

        return $user;
    }

    private function guardianLogin(array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'email' => null, 'username' => '01711111111', 'phone' => '01711111111', 'password' => 'guardian-pass',
        ], $attributes));
        $user->assignRole('parent');

        return $user;
    }

    public function test_login_with_an_email_returns_the_data_token_and_user_shape(): void
    {
        $this->postJson('/api/login', ['login' => 'admin@sms.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email', 'username', 'phone', 'is_active', 'roles', 'permissions']]])
            ->assertJsonPath('data.user.email', 'admin@sms.com')
            ->assertJsonPath('data.user.roles', ['admin'])
            ->assertJsonMissingPath('data.user.password');

        $this->assertContains('view-students', $this->postJson('/api/login', ['login' => 'admin@sms.com', 'password' => 'password'])->json('data.user.permissions'));
    }

    public function test_login_with_the_email_alias_still_works(): void
    {
        $this->postJson('/api/login', ['email' => 'admin@sms.com', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('data.user.email', 'admin@sms.com');
    }

    public function test_email_login_is_case_insensitive(): void
    {
        $this->postJson('/api/login', ['login' => 'Admin@SMS.com', 'password' => 'password'])->assertOk();
    }

    public function test_login_with_a_student_id(): void
    {
        $student = $this->studentLogin();

        $this->postJson('/api/login', ['login' => '20260001', 'password' => 'student-pass'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $student->id)
            ->assertJsonPath('data.user.username', '20260001')
            ->assertJsonPath('data.user.roles', ['student']);
    }

    public function test_login_with_a_guardian_mobile_including_the_country_prefix(): void
    {
        $guardian = $this->guardianLogin();

        foreach (['01711111111', '+8801711111111', '8801711111111', '0171 111 1111'] as $login) {
            $this->postJson('/api/login', ['login' => $login, 'password' => 'guardian-pass'])
                ->assertOk()
                ->assertJsonPath('data.user.id', $guardian->id);
        }
    }

    public function test_device_name_names_the_token(): void
    {
        $this->postJson('/api/login', ['login' => 'admin@sms.com', 'password' => 'password', 'device_name' => 'iPhone 17'])->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', ['name' => 'iPhone 17']);
    }

    public function test_a_wrong_password_or_unknown_login_returns_422(): void
    {
        $this->studentLogin();

        $this->postJson('/api/login', ['login' => '20260001', 'password' => 'wrong'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('login');
        $this->postJson('/api/login', ['login' => 'nobody@example.com', 'password' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('login');
    }

    public function test_an_inactive_account_returns_422(): void
    {
        $this->studentLogin(['is_active' => false]);

        $this->postJson('/api/login', ['login' => '20260001', 'password' => 'student-pass'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.login.0', 'Your account has been deactivated.');
    }

    public function test_login_requires_both_fields(): void
    {
        $this->postJson('/api/login', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['login', 'password']);
        $this->postJson('/api/login', ['login' => ['a'], 'password' => 'x'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('login');
    }

    public function test_me_returns_the_data_wrapped_user(): void
    {
        $token = $this->postJson('/api/login', ['login' => 'admin@sms.com', 'password' => 'password'])->json('data.token');

        $this->withToken($token)->getJson('/api/me')
            ->assertOk()
            ->assertJsonStructure(['data' => ['id', 'name', 'email', 'username', 'phone', 'is_active', 'roles', 'permissions']])
            ->assertJsonPath('data.email', 'admin@sms.com');
    }

    public function test_me_requires_a_token(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/logout')->assertUnauthorized();
        $this->postJson('/api/change-password', [])->assertUnauthorized();
    }

    public function test_logout_revokes_the_token(): void
    {
        $token = $this->postJson('/api/login', ['login' => 'admin@sms.com', 'password' => 'password'])->json('data.token');

        $this->withToken($token)->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_change_password(): void
    {
        $user = $this->studentLogin();
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/change-password', ['current_password' => 'nope', 'new_password' => 'new-password-1', 'new_password_confirmation' => 'new-password-1'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
        $this->postJson('/api/change-password', ['current_password' => 'student-pass', 'new_password' => 'short', 'new_password_confirmation' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_password');
        $this->postJson('/api/change-password', ['current_password' => 'student-pass', 'new_password' => 'new-password-1', 'new_password_confirmation' => 'different'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_password');

        $this->postJson('/api/change-password', ['current_password' => 'student-pass', 'new_password' => 'new-password-1', 'new_password_confirmation' => 'new-password-1'])
            ->assertOk()
            ->assertJsonPath('message', 'Password changed successfully');

        $this->postJson('/api/login', ['login' => '20260001', 'password' => 'new-password-1'])->assertOk();
    }

    public function test_the_admin_spa_shell_is_still_served(): void
    {
        $this->get('/admin/login')->assertOk();
    }

    public function test_login_is_throttled_per_identifier_and_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['login' => 'admin@sms.com', 'password' => 'wrong'])->assertUnprocessable();
        }

        // The next attempt is refused, even with the right password and a variant spelling.
        $this->postJson('/api/login', ['login' => 'ADMIN@sms.com', 'password' => 'password'])
            ->assertStatus(429)
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('errors');

        // Another identifier has its own bucket.
        $this->postJson('/api/login', ['login' => 'someone@example.com', 'password' => 'x'])->assertUnprocessable();
    }

    private function loginFrom(string $ip, string $login, string $password)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson('/api/login', ['login' => $login, 'password' => $password]);
    }

    private function failFromManyIps(string $login, int $count, string $prefix = '10.0'): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->loginFrom("{$prefix}.".intdiv($i, 200).'.'.($i % 200 + 1), $login, 'wrong')->assertUnprocessable();
        }
    }

    private function assertRetryAfter($response): void
    {
        $response->assertStatus(429)->assertJsonStructure(['message'])->assertJsonMissingPath('errors');
        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
    }

    public function test_a_successful_login_marks_the_ip_as_trusted_for_that_user(): void
    {
        $user = $this->studentLogin();

        $this->loginFrom('10.9.0.1', '20260001', 'student-pass')->assertOk();

        $this->assertTrue(Cache::has('login-trusted:'.$user->id.':0:'.sha1('10.9.0.1')));
    }

    public function test_the_per_account_lock_returns_retry_after_and_lifts_when_cleared(): void
    {
        $user = $this->studentLogin();
        $this->failFromManyIps('20260001', 10);

        $this->assertRetryAfter($this->loginFrom('10.8.0.1', '20260001', 'student-pass'));

        RateLimiter::clear('login-user:'.$user->id);
        $this->loginFrom('10.8.0.1', '20260001', 'student-pass')->assertOk();
    }

    public function test_a_trusted_ip_signs_in_while_the_account_is_locked_elsewhere(): void
    {
        $this->studentLogin();
        $this->loginFrom('10.7.0.1', '20260001', 'student-pass')->assertOk();

        $this->failFromManyIps('20260001', 10);

        $this->assertRetryAfter($this->loginFrom('10.8.0.1', '20260001', 'student-pass'));
        $this->loginFrom('10.7.0.1', '20260001', 'student-pass')->assertOk();
    }

    public function test_trust_is_per_user(): void
    {
        $this->studentLogin();
        $this->guardianLogin();
        $this->loginFrom('10.7.0.1', '20260001', 'student-pass')->assertOk();

        $this->failFromManyIps('01711111111', 10);

        $this->assertRetryAfter($this->loginFrom('10.7.0.1', '01711111111', 'guardian-pass'));
    }

    public function test_trust_expires_after_30_days(): void
    {
        $this->studentLogin();
        $this->loginFrom('10.7.0.1', '20260001', 'student-pass')->assertOk();
        $this->failFromManyIps('20260001', 10);

        $this->travel(29)->days();
        $this->loginFrom('10.7.0.1', '20260001', 'student-pass')->assertOk();
        $this->travel(31)->days();
        // The failure bucket has decayed by now, so lock it again before checking trust is gone.
        $this->failFromManyIps('20260001', 10, '10.6');
        $this->assertRetryAfter($this->loginFrom('10.7.0.1', '20260001', 'student-pass'));
    }

    public function test_failures_from_a_trusted_ip_still_count_toward_its_ip_limit(): void
    {
        $this->studentLogin();
        $this->loginFrom('10.7.0.1', '20260001', 'student-pass')->assertOk();

        for ($i = 0; $i < 30; $i++) {
            $this->loginFrom('10.7.0.1', "nobody{$i}@example.com", 'x')->assertUnprocessable();
        }

        $this->assertRetryAfter($this->loginFrom('10.7.0.1', '20260001', 'student-pass'));
    }

    public function test_login_is_throttled_per_ip_across_identifiers(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->postJson('/api/login', ['login' => "nobody{$i}@example.com", 'password' => 'x'])->assertUnprocessable();
        }

        $this->assertRetryAfter($this->postJson('/api/login', ['login' => 'admin@sms.com', 'password' => 'password']));
    }

    public function test_successful_logins_never_count_toward_the_ip_limit(): void
    {
        for ($i = 0; $i < 40; $i++) {
            User::factory()->create(['email' => "user{$i}@example.com", 'password' => 'good-pass-1'])
                ->assignRole('student');

            $this->postJson('/api/login', ['login' => "user{$i}@example.com", 'password' => 'good-pass-1'])->assertOk();
        }
    }

    public function test_the_route_limiter_429_has_retry_after(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', ['login' => 'someone@example.com', 'password' => 'x'])->assertUnprocessable();
        }

        $this->assertRetryAfter($this->postJson('/api/login', ['login' => 'someone@example.com', 'password' => 'x']));
    }

    public function test_failures_are_throttled_per_account_across_ips_and_spellings(): void
    {
        $this->studentLogin();
        $logins = ['20260001', ' 20260001', '20260001 ', '20260001', '20260001'];

        // Distinct IPs; the identifier spellings keep the route limiter's buckets apart.
        for ($i = 0; $i < 10; $i++) {
            $this->loginFrom("10.0.0.{$i}", $logins[$i % 5], 'wrong')->assertUnprocessable();
        }

        $this->assertRetryAfter($this->loginFrom('10.0.0.99', '20260001', 'student-pass'));
    }

    public function test_a_successful_login_resets_the_account_failure_count(): void
    {
        $this->studentLogin();

        for ($round = 0; $round < 3; $round++) {
            for ($i = 0; $i < 4; $i++) {
                $this->withServerVariables(['REMOTE_ADDR' => '10.1.'.$round.'.'.$i])
                    ->postJson('/api/login', ['login' => '20260001', 'password' => 'wrong'])->assertUnprocessable();
            }
            $this->withServerVariables(['REMOTE_ADDR' => "10.2.0.{$round}"])
                ->postJson('/api/login', ['login' => '20260001', 'password' => 'student-pass'])->assertOk();
        }
    }

    public function test_change_password_revokes_the_other_tokens_but_keeps_the_current_one(): void
    {
        $user = $this->studentLogin();
        $current = $user->createToken('this-device')->plainTextToken;
        $user->createToken('other-device');
        $this->assertSame(2, $user->tokens()->count());

        $this->withToken($current)->postJson('/api/change-password', [
            'current_password' => 'student-pass', 'new_password' => 'new-password-1', 'new_password_confirmation' => 'new-password-1',
        ])->assertOk();

        $this->assertSame(['this-device'], $user->tokens()->pluck('name')->all());
    }
}
