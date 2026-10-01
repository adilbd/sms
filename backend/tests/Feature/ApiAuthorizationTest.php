<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Authenticated API routes that intentionally only need a signed-in user.
     */
    private const OPEN_TO_ANY_USER = [
        'POST api/logout',
        'GET api/me',
        'POST api/change-password',
        'GET api/academic-years',
        'GET api/academic-years/{academic_year}',
        'GET api/shifts',
        'GET api/shifts/{shift}',
    ];

    public function test_every_authenticated_api_route_checks_a_permission_or_role(): void
    {
        $unguarded = collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => Str::startsWith($route->uri(), 'api/'))
            ->filter(fn (Route $route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
            ->reject(fn (Route $route) => collect($route->gatherMiddleware())
                ->contains(fn ($m) => is_string($m) && Str::startsWith($m, ['permission:', 'role:'])))
            ->map(fn (Route $route) => $this->routeKey($route))
            ->reject(fn (string $key) => in_array($key, self::OPEN_TO_ANY_USER, true))
            ->values()
            ->all();

        $this->assertSame([], $unguarded, 'Authenticated API routes without a permission or role check.');
    }

    public function test_roles_get_only_their_seeded_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $teacher = $this->userWithRole('teacher');
        $student = $this->userWithRole('student');
        $parent = $this->userWithRole('parent');

        // Teachers can read students and classes, but not change them.
        $this->actingAs($teacher, 'sanctum')->getJson('/api/students')->assertOk();
        $this->actingAs($teacher, 'sanctum')->getJson('/api/classes')->assertOk();
        $this->actingAs($teacher, 'sanctum')->postJson('/api/students', [])->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->postJson('/api/classes', [])->assertForbidden();

        // Teachers mark attendance but can't delete classes or touch fees.
        $classId = DB::table('classes')->insertGetId(['name' => 'Class 1', 'code' => 'C1']);
        $this->actingAs($teacher, 'sanctum')->putJson('/api/attendance/sheet', [])->assertUnprocessable();
        $this->actingAs($teacher, 'sanctum')->deleteJson("/api/classes/{$classId}")->assertForbidden();
        $this->assertDatabaseHas('classes', ['id' => $classId, 'deleted_at' => null]);
        $this->actingAs($teacher, 'sanctum')->getJson('/api/fee-payments')->assertForbidden();

        // Students and guardians read their own attendance only: no school-wide permission.
        $this->actingAs($student, 'sanctum')->getJson('/api/attendance/report?section_id=1')->assertForbidden();
        $this->actingAs($parent, 'sanctum')->getJson('/api/attendance/sheet?section_id=1')->assertForbidden();
        $this->actingAs($parent, 'sanctum')->putJson('/api/attendance/sheet', [])->assertForbidden();
        $this->actingAs($student, 'sanctum')->getJson('/api/holidays')->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->getJson('/api/holidays')->assertOk();
        $this->actingAs($teacher, 'sanctum')->postJson('/api/holidays', [])->assertForbidden();

        // Students can't list other students or staff.
        $this->actingAs($student, 'sanctum')->getJson('/api/students')->assertForbidden();
        $this->actingAs($student, 'sanctum')->getJson('/api/staff')->assertForbidden();
        $this->actingAs($student, 'sanctum')->getJson('/api/exams')->assertForbidden();
        $this->actingAs($parent, 'sanctum')->getJson('/api/exams')->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->getJson('/api/exams')->assertOk();
        $this->actingAs($teacher, 'sanctum')->postJson('/api/exams', [])->assertForbidden();

        // Parents can't list students (they read their own children instead) ...
        $this->actingAs($parent, 'sanctum')->getJson('/api/students')->assertForbidden();
        $this->actingAs($parent, 'sanctum')->getJson('/api/my/children')->assertOk();
        $this->actingAs($parent, 'sanctum')->getJson('/api/my/student')->assertForbidden();
        // ... and students read only their own record.
        $this->actingAs($student, 'sanctum')->getJson('/api/my/children')->assertForbidden();
        $this->actingAs($student, 'sanctum')->getJson('/api/my/student')->assertNotFound();

        // Parents can see fees but not collect them.
        $this->actingAs($parent, 'sanctum')->getJson('/api/fee-payments')->assertOk();
        $this->actingAs($parent, 'sanctum')->postJson('/api/fee-payments', [])->assertForbidden();

        // Academic years are readable by everyone, but only settings admins change them.
        $this->actingAs($student, 'sanctum')->getJson('/api/academic-years')->assertOk();
        $this->actingAs($teacher, 'sanctum')->postJson('/api/academic-years', [])->assertForbidden();

        // Dashboard stats are admin-only (view-reports).
        $this->actingAs($teacher, 'sanctum')->getJson('/api/dashboard/stats')->assertForbidden();
    }

    public function test_admin_keeps_full_access(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::where('email', 'admin@sms.com')->firstOrFail();

        foreach (['/api/students', '/api/classes', '/api/sections', '/api/holidays', '/api/fee-payments', '/api/staff'] as $uri) {
            $this->actingAs($admin, 'sanctum')->getJson($uri)->assertOk();
        }

        $this->actingAs($admin, 'sanctum')->postJson('/api/academic-years', [])->assertUnprocessable();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function routeKey(Route $route): string
    {
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();

        return "{$method} {$route->uri()}";
    }
}
