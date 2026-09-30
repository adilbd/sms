<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class RemoveLegacyStudentsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_users_with_nothing_but_student_or_parent_roles_are_deleted(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $student = User::factory()->create();
        $student->assignRole('student');
        $parent = User::factory()->create();
        $parent->assignRole('parent');
        $adminParent = User::factory()->create();
        $adminParent->assignRole('admin', 'parent');
        $teacherStudent = User::factory()->create();
        $teacherStudent->assignRole('teacher', 'student');
        $student->createToken('t');
        $adminParent->createToken('t');

        $migration = require database_path('migrations/2026_10_02_000001_remove_legacy_students_and_parents.php');
        $method = new ReflectionMethod($migration, 'deleteUsersWithRoles');
        $method->invoke($migration, ['student', 'parent']);

        $this->assertDatabaseMissing('users', ['id' => $student->id]);
        $this->assertDatabaseMissing('users', ['id' => $parent->id]);
        $this->assertDatabaseHas('users', ['id' => $adminParent->id]);
        $this->assertDatabaseHas('users', ['id' => $teacherStudent->id]);

        $this->assertSame(['admin'], $adminParent->fresh()->getRoleNames()->all());
        $this->assertSame(['teacher'], $teacherStudent->fresh()->getRoleNames()->all());
        $this->assertSame(1, $adminParent->tokens()->count(), 'a surviving user keeps their tokens');
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }
}
