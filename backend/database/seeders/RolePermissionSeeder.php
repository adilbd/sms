<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

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
            // Parent permissions
            'view-parents', 'create-parents', 'edit-parents', 'delete-parents',
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
            Permission::create(['name' => $permission]);
        }

        // Create roles and assign permissions
        $adminRole = Role::create(['name' => 'admin']);
        $adminRole->givePermissionTo(Permission::all());

        $teacherRole = Role::create(['name' => 'teacher']);
        $teacherRole->givePermissionTo([
            'view-students', 'view-attendance', 'mark-attendance',
            'view-exams', 'view-results', 'enter-results', 'edit-results',
            'view-subjects', 'view-classes',
        ]);

        $studentRole = Role::create(['name' => 'student']);
        $studentRole->givePermissionTo([
            'view-attendance', 'view-exams', 'view-results', 'view-subjects',
        ]);

        $parentRole = Role::create(['name' => 'parent']);
        $parentRole->givePermissionTo([
            'view-students', 'view-attendance', 'view-exams', 'view-results', 'view-fees',
        ]);

        // Create super admin user
        $admin = User::create([
            'name' => 'Super Admin',
            'email' => 'admin@sms.com',
            'password' => Hash::make('password'),
            'phone' => '1234567890',
            'is_active' => true,
        ]);
        $admin->assignRole('admin');
    }
}

