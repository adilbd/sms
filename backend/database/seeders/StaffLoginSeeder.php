<?php

namespace Database\Seeders;

use App\Models\Staff;
use App\Models\User;
use App\Support\Username;
use Illuminate\Database\Seeder;

/**
 * Demo staff logins (password "password"): two teachers and one office staff member from
 * the committed staff fixture, signing in with their employee ID (e.g. VHBUB-3). Matched
 * on employee_id and skipped once the staff member has a login, so reruns add nothing.
 * Run after RolePermissionSeeder (the roles) and StaffSeeder (the staff). Writes through
 * the models directly, like StaffSeeder; real logins are created from the admin Staff form.
 */
class StaffLoginSeeder extends Seeder
{
    /** @var array<string, string> employee_id => role */
    public const LOGINS = [
        'VHBUB-3' => 'teacher',
        'VHBUB-9' => 'teacher',
        'VHBUB-52' => 'office',
    ];

    public function run(): void
    {
        foreach (self::LOGINS as $employeeId => $role) {
            $staff = Staff::where('employee_id', $employeeId)->first();

            if (! $staff || $staff->status !== Staff::STATUS_ACTIVE || $staff->user_id !== null) {
                continue;
            }

            $username = Username::normalize($employeeId);

            // Never take over an existing account that isn't this staff login.
            if (User::where('username', $username)->exists()) {
                continue;
            }

            $user = User::create([
                'name' => $staff->name_en ?: $staff->name_bn,
                'username' => $username,
                'password' => 'password',
                'is_active' => true,
            ]);
            $user->assignRole($role);

            $staff->update(['user_id' => $user->id, 'login_enabled' => true]);
        }
    }
}
