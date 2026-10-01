<?php

namespace Tests\Feature;

use App\Models\Staff;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\StaffLoginSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffLoginSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_twice_creates_no_duplicates(): void
    {
        $this->seed(RolePermissionSeeder::class);
        foreach (['VHBUB-3', 'VHBUB-9'] as $id) {
            Staff::factory()->create(['employee_id' => $id]);
        }
        Staff::factory()->create(['employee_id' => 'VHBUB-52', 'category' => 'staff', 'position' => 'staff']);

        $this->seed(StaffLoginSeeder::class);
        $users = User::count();
        $this->seed(StaffLoginSeeder::class);

        $this->assertSame($users, User::count());
        $this->assertSame(4, $users); // the admin plus the three demo logins

        $this->assertTrue(User::where('username', 'vhbub-3')->firstOrFail()->hasRole('teacher'));
        $this->assertTrue(User::where('username', 'vhbub-9')->firstOrFail()->hasRole('teacher'));
        $this->assertTrue(User::where('username', 'vhbub-52')->firstOrFail()->hasRole('office'));
        $this->assertSame(3, Staff::whereNotNull('user_id')->count());

        $this->postJson('/api/login', ['login' => 'VHBUB-3', 'password' => 'password'])->assertOk();
    }

    public function test_it_skips_missing_or_former_staff_and_taken_usernames(): void
    {
        $this->seed(RolePermissionSeeder::class);
        Staff::factory()->former()->create(['employee_id' => 'VHBUB-3']);
        User::factory()->create(['username' => 'vhbub-9', 'email' => null]);
        Staff::factory()->create(['employee_id' => 'VHBUB-9']);

        $this->seed(StaffLoginSeeder::class);

        $this->assertSame(0, Staff::whereNotNull('user_id')->count());
        $this->assertSame(2, User::count());
    }
}
