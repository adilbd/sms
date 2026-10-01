<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Staff logins: the `login` block of the staff store/update payload, the resulting users,
 * and the office role's permissions (docs/tasks/staff-logins.md).
 */
class StaffLoginApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@sms.com')->firstOrFail();
        $this->shift = Shift::factory()->create();
        Cache::flush();
    }

    private function payload(array $override = []): array
    {
        return array_replace_recursive([
            'name_en' => 'Md. Karim',
            'employee_id' => 'VHBUB-12',
            'category' => 'teacher',
            'position' => 'teacher',
            'status' => 'active',
            'shift_ids' => [$this->shift->id],
        ], $override);
    }

    private function teacherLogin(array $login = [], array $override = []): array
    {
        return $this->payload($override + [
            'login' => $login + ['enabled' => true, 'role' => 'teacher', 'password' => 'secret-pass', 'email' => 'Karim@Example.com'],
        ]);
    }

    private function create(array $payload)
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/staff', $payload);
    }

    private function staffWithLogin(string $category = 'teacher'): Staff
    {
        $role = $category === 'teacher' ? 'teacher' : 'office';
        $id = $this->create($this->teacherLogin(['role' => $role], ['category' => $category, 'position' => $category === 'teacher' ? 'teacher' : 'staff']))
            ->assertCreated()->json('data.id');

        return Staff::findOrFail($id);
    }

    // --- happy path -------------------------------------------------------------------

    public function test_enabling_a_login_creates_the_user_with_the_employee_id_as_username(): void
    {
        $response = $this->create($this->teacherLogin())
            ->assertCreated()
            ->assertJsonPath('data.login.enabled', true)
            ->assertJsonPath('data.login.username', 'vhbub-12')
            ->assertJsonPath('data.login.email', 'karim@example.com')
            ->assertJsonPath('data.login.role', 'teacher')
            ->assertJsonPath('data.login.is_active', true)
            ->assertJsonMissingPath('data.login.password');

        $staff = Staff::findOrFail($response->json('data.id'));
        $user = User::findOrFail($staff->user_id);
        $this->assertSame('vhbub-12', $user->username);
        $this->assertSame('Md. Karim', $user->name);
        $this->assertTrue($user->hasRole('teacher'));
        $this->assertTrue($user->is_active);
    }

    public function test_the_staff_member_signs_in_with_the_employee_id_or_the_email(): void
    {
        $this->create($this->teacherLogin())->assertCreated();

        $this->postJson('/api/login', ['login' => 'VHBUB-12', 'password' => 'secret-pass'])
            ->assertOk()->assertJsonPath('data.user.roles', ['teacher']);
        $this->postJson('/api/login', ['login' => 'vhbub-12', 'password' => 'secret-pass'])->assertOk();
        $this->postJson('/api/login', ['login' => 'karim@example.com', 'password' => 'secret-pass'])->assertOk();
        $this->postJson('/api/login', ['login' => 'VHBUB-12', 'password' => 'wrong-pass'])->assertUnprocessable();
    }

    public function test_non_teaching_staff_get_the_office_role(): void
    {
        $staff = $this->staffWithLogin('staff');

        $this->assertTrue(User::findOrFail($staff->user_id)->hasRole('office'));
        $this->postJson('/api/login', ['login' => 'VHBUB-12', 'password' => 'secret-pass'])
            ->assertOk()->assertJsonPath('data.user.roles', ['office']);
    }

    public function test_a_password_reset_on_update_works_and_signs_the_user_out(): void
    {
        $staff = $this->staffWithLogin();
        $this->signInAndExpect();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/staff/{$staff->id}", [
            'login' => ['enabled' => true, 'role' => 'teacher', 'password' => 'brand-new-pass'],
        ])->assertOk()->assertJsonPath('data.login.is_active', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->postJson('/api/login', ['login' => 'VHBUB-12', 'password' => 'secret-pass'])->assertUnprocessable();
        $this->postJson('/api/login', ['login' => 'VHBUB-12', 'password' => 'brand-new-pass'])->assertOk();
    }

    public function test_updating_without_a_password_keeps_the_old_one(): void
    {
        $staff = $this->staffWithLogin();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/staff/{$staff->id}", [
            'login' => ['enabled' => true, 'role' => 'teacher'],
        ])->assertOk();

        $this->postJson('/api/login', ['login' => 'VHBUB-12', 'password' => 'secret-pass'])->assertOk();
    }

    public function test_changing_the_employee_id_updates_the_username(): void
    {
        $staff = $this->staffWithLogin();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/staff/{$staff->id}", ['employee_id' => 'VHBUB-99'])
            ->assertOk()->assertJsonPath('data.login.username', 'vhbub-99');

        $this->postJson('/api/login', ['login' => 'VHBUB-99', 'password' => 'secret-pass'])->assertOk();
        $this->postJson('/api/login', ['login' => 'VHBUB-12', 'password' => 'secret-pass'])->assertUnprocessable();
    }

    public function test_a_staff_member_without_a_login_has_an_empty_login_block(): void
    {
        $id = $this->create($this->payload())->assertCreated()
            ->assertJsonPath('data.login.enabled', false)
            ->assertJsonPath('data.login.username', null)
            ->assertJsonPath('data.login.role', null)
            ->json('data.id');

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/staff/{$id}")->assertOk()->assertJsonPath('data.login.enabled', false);
        $this->assertSame(1, User::count());
    }

    public function test_the_login_is_never_in_the_public_staff_shape(): void
    {
        $staff = $this->staffWithLogin();

        $this->getJson("/api/public/staff/{$staff->id}")->assertOk()->assertJsonMissingPath('data.login');
        $this->getJson('/api/public/staff')->assertOk()->assertJsonMissingPath('data.0.login');
    }

    // --- validation -------------------------------------------------------------------

    public function test_enabling_a_login_needs_an_employee_id(): void
    {
        $this->create($this->teacherLogin([], ['employee_id' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['employee_id']);
        $this->assertSame(1, User::count());
        $this->assertSame(0, Staff::count());
    }

    public function test_the_role_must_match_the_category(): void
    {
        $this->create($this->teacherLogin(['role' => 'office']))
            ->assertUnprocessable()->assertJsonValidationErrors(['login.role']);

        $this->create($this->teacherLogin(['role' => 'teacher'], ['category' => 'staff', 'position' => 'staff']))
            ->assertUnprocessable()->assertJsonValidationErrors(['login.role']);

        $this->create($this->teacherLogin(['role' => 'admin']))
            ->assertUnprocessable()->assertJsonValidationErrors(['login.role']);

        $this->create($this->teacherLogin(['role' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['login.role']);
        $this->assertSame(0, Staff::count());
    }

    public function test_a_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'karim@example.com']);

        $this->create($this->teacherLogin())
            ->assertUnprocessable()->assertJsonValidationErrors(['login.email']);
        $this->assertSame(0, Staff::count());
    }

    public function test_an_employee_id_that_is_another_users_username_is_rejected(): void
    {
        User::factory()->create(['username' => 'vhbub-12', 'email' => null]);

        $this->create($this->teacherLogin())
            ->assertUnprocessable()->assertJsonValidationErrors(['employee_id']);
        $this->assertSame(0, Staff::count());
    }

    public function test_a_password_is_required_when_the_login_is_first_created(): void
    {
        $this->create($this->teacherLogin(['password' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['login.password']);
        $this->create($this->teacherLogin(['password' => 'short']))
            ->assertUnprocessable()->assertJsonValidationErrors(['login.password']);
        $this->assertSame(0, Staff::count());
        $this->assertSame(1, User::count());
    }

    public function test_a_staff_member_linked_to_a_student_or_parent_user_cannot_get_a_login(): void
    {
        foreach (['student', 'parent'] as $role) {
            $other = User::factory()->create(['username' => $role.'-user', 'email' => null]);
            $other->assignRole($role);
            $staff = Staff::factory()->create(['user_id' => $other->id, 'employee_id' => 'EMP-'.$role]);
            $staff->shifts()->attach($this->shift);

            $this->actingAs($this->admin, 'sanctum')->putJson("/api/staff/{$staff->id}", [
                'login' => ['enabled' => true, 'role' => 'teacher', 'password' => 'secret-pass'],
            ])->assertUnprocessable()->assertJsonValidationErrors(['login.enabled']);

            $this->assertTrue($other->fresh()->hasRole($role));
            $this->assertFalse($other->fresh()->hasRole('teacher'));
        }
    }

    public function test_the_login_block_is_validated(): void
    {
        $this->create($this->payload(['login' => ['role' => 'teacher']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['login.enabled']);
        $this->create($this->teacherLogin(['email' => 'not-an-email']))
            ->assertUnprocessable()->assertJsonValidationErrors(['login.email']);
    }

    // --- deactivation -----------------------------------------------------------------

    private function signInAndExpect(): string
    {
        return $this->postJson('/api/login', ['login' => 'VHBUB-12', 'password' => 'secret-pass'])->assertOk()->json('data.token');
    }

    public function test_disabling_the_login_deactivates_the_user_and_revokes_tokens(): void
    {
        $staff = $this->staffWithLogin();
        $token = $this->signInAndExpect();
        $user = User::findOrFail($staff->user_id);

        $this->withToken($token)->getJson('/api/me')->assertOk();
        $this->app['auth']->forgetGuards();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/staff/{$staff->id}", ['login' => ['enabled' => false]])
            ->assertOk()->assertJsonPath('data.login.is_active', false)->assertJsonPath('data.login.enabled', true);

        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/login', ['login' => 'VHBUB-12', 'password' => 'secret-pass'])->assertUnprocessable();
    }

    public function test_re_enabling_restores_access(): void
    {
        $staff = $this->staffWithLogin();
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/staff/{$staff->id}", ['login' => ['enabled' => false]])->assertOk();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/staff/{$staff->id}", ['login' => ['enabled' => true, 'role' => 'teacher']])
            ->assertOk()->assertJsonPath('data.login.is_active', true);

        $this->postJson('/api/login', ['login' => 'VHBUB-12', 'password' => 'secret-pass'])->assertOk();
        $this->assertSame(1, User::where('username', 'vhbub-12')->count());
    }

    public function test_a_former_status_deactivates_the_login_and_returning_reactivates_it(): void
    {
        $staff = $this->staffWithLogin();
        $token = $this->signInAndExpect();
        $user = User::findOrFail($staff->user_id);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/staff/{$staff->id}", ['status' => 'retired', 'leaving_date' => '2026-06-30'])
            ->assertOk()->assertJsonPath('data.login.is_active', false);

        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/staff/{$staff->id}", ['status' => 'active'])
            ->assertOk()->assertJsonPath('data.login.is_active', true);

        $this->assertTrue($user->fresh()->is_active);
        $this->postJson('/api/login', ['login' => 'VHBUB-12', 'password' => 'secret-pass'])->assertOk();
    }

    public function test_deleting_the_staff_member_deactivates_the_login(): void
    {
        $staff = $this->staffWithLogin();
        $token = $this->signInAndExpect();
        $user = User::findOrFail($staff->user_id);

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/staff/{$staff->id}")->assertNoContent();

        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/login', ['login' => 'VHBUB-12', 'password' => 'secret-pass'])->assertUnprocessable();
    }

    public function test_a_failed_update_does_not_leave_a_half_written_login(): void
    {
        $staff = $this->staffWithLogin();
        $other = User::factory()->create(['username' => 'vhbub-77', 'email' => null]);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/staff/{$staff->id}", ['employee_id' => 'VHBUB-77', 'name_en' => 'Changed'])
            ->assertUnprocessable()->assertJsonValidationErrors(['employee_id']);

        $this->assertSame('VHBUB-12', $staff->fresh()->employee_id);
        $this->assertSame('Md. Karim', $staff->fresh()->name_en);
        $this->assertSame('vhbub-12', User::findOrFail($staff->user_id)->username);
        $this->assertNotNull($other);
    }

    // --- office role ------------------------------------------------------------------

    public function test_the_office_role_has_exactly_the_listed_permissions(): void
    {
        $role = \Spatie\Permission\Models\Role::findByName('office');

        $this->assertEqualsCanonicalizing([
            'view-students', 'create-students', 'edit-students',
            'view-fees', 'create-fees', 'collect-fees',
            'view-classes', 'view-subjects',
        ], $role->permissions->pluck('name')->all());
    }

    public function test_the_office_role_can_view_students_and_has_the_fee_permissions(): void
    {
        $staff = $this->staffWithLogin('staff');
        $office = User::findOrFail($staff->user_id);
        $student = Student::factory()->create();

        $this->actingAs($office, 'sanctum')->getJson('/api/students')->assertOk();
        $this->actingAs($office, 'sanctum')->getJson("/api/students/{$student->id}")->assertOk();
        $this->actingAs($office, 'sanctum')->getJson('/api/classes')->assertOk();
        $this->actingAs($office, 'sanctum')->getJson('/api/subjects')->assertOk();
        // The fee endpoints are stubs, so the permissions are checked directly.
        $this->assertTrue($office->can('view-fees'));
        $this->assertTrue($office->can('collect-fees'));
        $this->assertTrue($office->can('create-fees'));
        $this->actingAs($office, 'sanctum')->getJson('/api/fee-payments')->assertOk();
    }

    public function test_the_office_role_is_refused_deletes_settings_and_exam_writes(): void
    {
        $staff = $this->staffWithLogin('staff');
        $office = User::findOrFail($staff->user_id);
        $student = Student::factory()->create();

        $this->actingAs($office, 'sanctum')->deleteJson("/api/students/{$student->id}")->assertForbidden();
        $this->actingAs($office, 'sanctum')->putJson('/api/settings/institute', ['name_en' => 'X'])->assertForbidden();
        $this->actingAs($office, 'sanctum')->postJson('/api/exams', [])->assertForbidden();
        $this->actingAs($office, 'sanctum')->getJson('/api/exams')->assertForbidden();
        $this->actingAs($office, 'sanctum')->getJson('/api/staff')->assertForbidden();
        $this->actingAs($office, 'sanctum')->getJson('/api/my/assignments')->assertForbidden();
    }

    public function test_the_role_permission_seeder_can_run_twice(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(1, \Spatie\Permission\Models\Role::where('name', 'office')->count());
        $this->assertCount(8, \Spatie\Permission\Models\Role::findByName('office')->permissions);
    }
}
