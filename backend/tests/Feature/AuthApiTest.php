<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_login_is_throttled_per_ip_across_identifiers(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/login', ['login' => "nobody{$i}@example.com", 'password' => 'x'])->assertUnprocessable();
        }

        $this->postJson('/api/login', ['login' => 'admin@sms.com', 'password' => 'password'])->assertStatus(429);
    }

    public function test_failures_are_throttled_per_account_across_ips_and_spellings(): void
    {
        // Distinct IPs and identifiers that all resolve to one account.
        $logins = ['20260001', ' 20260001', '20260001 ', '20260001', '20260001'];
        $this->studentLogin();

        foreach ($logins as $i => $login) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->postJson('/api/login', ['login' => $login, 'password' => 'wrong'])->assertUnprocessable();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.99'])
            ->postJson('/api/login', ['login' => '20260001', 'password' => 'student-pass'])
            ->assertStatus(429);
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
