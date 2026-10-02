<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FeeTablesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_fee_tables_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('fee_heads', ['name_en', 'name_bn', 'code', 'kind', 'is_active', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('fee_rates', ['fee_head_id', 'class_id', 'academic_year_id', 'group', 'amount', 'due_day']));
        $this->assertTrue(Schema::hasColumns('student_fee_waivers', ['student_id', 'academic_year_id', 'fee_head_id', 'percent', 'fixed_amount', 'reason', 'approved_by']));
        $this->assertTrue(Schema::hasColumns('fee_dues', ['student_id', 'enrolment_id', 'fee_head_id', 'period', 'amount', 'waiver_amount', 'net_amount', 'paid_amount', 'status', 'due_date']));
        $this->assertTrue(Schema::hasColumns('fee_payments', ['receipt_no', 'student_id', 'paid_at', 'method', 'transaction_id', 'amount', 'collected_by', 'note', 'cancelled_at', 'cancelled_by', 'cancel_reason']));
        $this->assertTrue(Schema::hasColumns('fee_payment_allocations', ['fee_payment_id', 'fee_due_id', 'amount']));
        $this->assertTrue(Schema::hasColumns('fee_receipt_counters', ['year', 'last_number']));
        $this->assertFalse(Schema::hasTable('fee_types'));
        $this->assertFalse(Schema::hasTable('fee_structures'));
    }

    public function test_the_migration_rolls_back_to_the_old_structure_and_runs_again(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 3])->assertSuccessful();

        foreach (['fee_heads', 'fee_rates', 'student_fee_waivers', 'fee_dues', 'fee_payment_allocations', 'fee_receipt_counters'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }
        $this->assertTrue(Schema::hasColumns('fee_types', ['name', 'code']));
        $this->assertTrue(Schema::hasColumns('fee_structures', ['fee_type_id', 'class_id', 'academic_year_id', 'frequency']));
        $this->assertTrue(Schema::hasColumns('fee_payments', ['receipt_number', 'amount_paid', 'payment_method']));
        $this->assertFalse(Schema::hasColumn('fee_payments', 'receipt_no'));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('fee_dues'));
        $this->assertTrue(Schema::hasColumn('fee_payments', 'receipt_no'));
        $this->assertFalse(Schema::hasTable('fee_types'));
    }

    public function test_it_takes_view_fees_away_from_students_and_guardians(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 3])->assertSuccessful();

        $permission = Permission::findOrCreate('view-fees', 'web');
        $keep = Permission::findOrCreate('collect-fees', 'web');
        foreach (['student', 'parent', 'office'] as $name) {
            Role::findOrCreate($name, 'web')->givePermissionTo($permission);
        }
        Role::findByName('parent', 'web')->givePermissionTo($keep);

        $this->artisan('migrate')->assertSuccessful();

        $granted = fn (string $role) => DB::table('role_has_permissions')
            ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('roles.name', $role)->pluck('permissions.name')->all();

        $this->assertSame([], $granted('student'));
        $this->assertSame(['collect-fees'], $granted('parent'));
        $this->assertSame(['view-fees'], $granted('office'));
    }
}
