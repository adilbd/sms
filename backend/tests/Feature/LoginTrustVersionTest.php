<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Support\LoginTrust;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * A trusted IP skips the per-account lock; it must stop doing so once the credentials change
 * or the login is switched off (docs/tasks/cleanup-batch.md).
 */
class LoginTrustVersionTest extends TestCase
{
    use RefreshDatabase;

    private const IP = '10.7.0.1';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@sms.com')->firstOrFail();
        Cache::flush();
    }

    private function createStudent(): Student
    {
        AcademicYear::factory()->create(['year' => 2026, 'is_active' => true]);
        $section = Section::factory()->create(['class_id' => Classes::factory()->create(['number' => 5])->id]);

        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', [
            'name_en' => 'Rahim Uddin', 'date_of_birth' => '2014-04-10', 'gender' => 'male', 'religion' => 'islam',
            'admission_date' => '2026-01-05', 'guardian_relation' => 'father', 'guardian_name' => 'Karim Uddin',
            'guardian_mobile' => '01711111111', 'guardian_password' => 'guardian-pass', 'password' => 'student-pass',
            'enrolment' => ['section_id' => $section->id, 'roll_number' => 1],
        ])->assertCreated()->json('data.id');

        return Student::findOrFail($id);
    }

    private function loginFrom(string $login, string $password)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::IP])->postJson('/api/login', ['login' => $login, 'password' => $password]);
    }

    public function test_the_trust_key_carries_a_version_that_invalidate_bumps(): void
    {
        $user = User::factory()->create();

        $this->assertSame("login-trusted:{$user->id}:0:".sha1(self::IP), LoginTrust::key($user, self::IP));

        LoginTrust::trust($user, self::IP);
        $this->assertTrue(LoginTrust::isTrusted($user, self::IP));

        LoginTrust::invalidate($user);

        $this->assertFalse(LoginTrust::isTrusted($user, self::IP));
        $this->assertSame("login-trusted:{$user->id}:1:".sha1(self::IP), LoginTrust::key($user, self::IP));
        $this->assertSame(1, Cache::get("login-trust-version:{$user->id}"));
    }

    public function test_invalidating_one_user_leaves_other_users_trusted(): void
    {
        [$a, $b] = [User::factory()->create(), User::factory()->create()];
        LoginTrust::trust($a, self::IP);
        LoginTrust::trust($b, self::IP);

        LoginTrust::invalidate($a);

        $this->assertTrue(LoginTrust::isTrusted($b, self::IP));
    }

    public function test_after_a_password_change_the_previously_trusted_ip_is_locked_again(): void
    {
        $user = User::factory()->create(['email' => null, 'username' => '20260001', 'password' => 'student-pass']);
        $user->assignRole('student');

        $this->loginFrom('20260001', 'student-pass')->assertOk();
        $this->assertTrue(LoginTrust::isTrusted($user, self::IP));

        $this->actingAs($user, 'sanctum')->postJson('/api/change-password', [
            'current_password' => 'student-pass', 'new_password' => 'new-password-1', 'new_password_confirmation' => 'new-password-1',
        ])->assertOk();

        $this->assertFalse(LoginTrust::isTrusted($user, self::IP));

        // The account is locked for IPs that are not trusted, and this IP no longer is.
        RateLimiter::increment('login-user:'.$user->id, 60, 10);
        $this->app['auth']->forgetGuards();
        $this->loginFrom('20260001', 'new-password-1')->assertStatus(429);
    }

    public function test_an_admin_password_reset_of_a_student_and_guardian_invalidates_their_trust(): void
    {
        $student = $this->createStudent();
        $login = User::findOrFail($student->user_id);
        $guardian = User::findOrFail($student->guardian_user_id);
        LoginTrust::trust($login, self::IP);
        LoginTrust::trust($guardian, self::IP);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/students/{$student->id}", ['password' => 'brand-new-pass', 'guardian_password' => 'guardian-new-pass'])
            ->assertOk();

        $this->assertFalse(LoginTrust::isTrusted($login, self::IP));
        $this->assertFalse(LoginTrust::isTrusted($guardian, self::IP));
    }

    public function test_deactivating_a_student_login_invalidates_its_trust(): void
    {
        $student = $this->createStudent();
        $login = User::findOrFail($student->user_id);
        LoginTrust::trust($login, self::IP);

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/students/{$student->id}")->assertNoContent();

        $this->assertFalse(LoginTrust::isTrusted($login, self::IP));
    }

    public function test_a_staff_password_reset_and_a_disabled_login_invalidate_trust(): void
    {
        $shift = Shift::factory()->create();
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/staff', [
            'name_en' => 'Md. Karim', 'employee_id' => 'VHBUB-12', 'category' => 'teacher', 'position' => 'teacher',
            'status' => 'active', 'shift_ids' => [$shift->id],
            'login' => ['enabled' => true, 'role' => 'teacher', 'password' => 'secret-pass'],
        ])->assertCreated()->json('data.id');
        $user = User::findOrFail(Staff::findOrFail($id)->user_id);

        LoginTrust::trust($user, self::IP);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/staff/{$id}", [
            'login' => ['enabled' => true, 'role' => 'teacher', 'password' => 'brand-new-pass'],
        ])->assertOk();
        $this->assertFalse(LoginTrust::isTrusted($user, self::IP));

        LoginTrust::trust($user, self::IP);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/staff/{$id}", ['login' => ['enabled' => false]])->assertOk();
        $this->assertFalse(LoginTrust::isTrusted($user, self::IP));
    }
}
