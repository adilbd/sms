<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Roles, permissions and the admin login. Safe to run repeatedly: permissions and roles
 * are found or created, each seeded role's permissions are synced to the list below
 * (which drops anything removed from it, such as the parent role's old view-students),
 * and the admin user is only created once.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions
        $permissions = [
            // Student permissions
            'view-students', 'create-students', 'edit-students', 'delete-students',
            // Teacher permissions
            'view-teachers', 'create-teachers', 'edit-teachers', 'delete-teachers',
            // Class permissions
            'view-classes', 'create-classes', 'edit-classes', 'delete-classes',
            // Subject permissions
            'view-subjects', 'create-subjects', 'edit-subjects', 'delete-subjects',
            // Attendance permissions
            'view-attendance', 'mark-attendance', 'edit-attendance', 'delete-attendance',
            // Exam permissions
            'view-exams', 'create-exams', 'edit-exams', 'delete-exams', 'publish-exams',
            // Result permissions
            'view-results', 'enter-results', 'edit-results', 'delete-results',
            // Fee permissions
            'view-fees', 'create-fees', 'edit-fees', 'delete-fees', 'collect-fees',
            // Report permissions
            'view-reports', 'generate-reports',
            // Settings permissions
            'view-settings', 'edit-settings',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Create roles and assign permissions
        Role::findOrCreate('admin', 'web')->syncPermissions(Permission::all());

        Role::findOrCreate('teacher', 'web')->syncPermissions([
            'view-students', 'view-attendance', 'mark-attendance',
            'view-exams', 'view-results', 'enter-results', 'edit-results',
            'view-subjects', 'view-classes',
        ]);

        Role::findOrCreate('student', 'web')->syncPermissions([
            'view-attendance', 'view-exams', 'view-results', 'view-subjects',
        ]);

        // Guardians read their own children through GET /api/my/children, not through
        // view-students (which would list every student in the school).
        Role::findOrCreate('parent', 'web')->syncPermissions([
            'view-attendance', 'view-exams', 'view-results', 'view-fees',
        ]);

        // Create super admin user
        $admin = User::firstOrCreate(
            ['email' => 'admin@sms.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'phone' => '1234567890',
                'is_active' => true,
            ],
        );
        $admin->assignRole('admin');
    }
}
