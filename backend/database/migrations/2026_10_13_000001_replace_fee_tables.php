<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The first-generation fee_types, fee_structures and fee_payments tables were never used
 * (the controllers were stubs), so they are dropped and replaced by fee heads, per-class
 * rates, per-student waivers, dues, payments (with receipt numbers) and their allocations.
 * See docs/tasks/fees.md. Money columns are decimal(10,2) BDT; the app does its arithmetic
 * in integer paisa (App\Support\Money).
 *
 * Uniqueness of (head, class, year, group) on fee_rates is also checked in
 * App\Services\FeeRateService, because a unique index treats a null group as distinct on
 * both MySQL and SQLite.
 *
 * down() drops the new tables (children first) and recreates the old structure without
 * data. The old fee_payments has no foreign key on student_id: the earlier
 * remove_legacy_students migration dropped it, and its own down() adds it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('fee_payments');
        Schema::dropIfExists('fee_structures');
        Schema::dropIfExists('fee_types');

        Schema::create('fee_heads', function (Blueprint $table) {
            $table->id();
            $table->string('name_en')->nullable();
            $table->string('name_bn')->nullable();
            $table->string('code', 30)->unique();
            $table->string('kind', 20); // monthly, one_time, per_exam
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fee_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_head_id')->constrained('fee_heads')->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->string('group', 30)->nullable();
            $table->decimal('amount', 10, 2);
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->timestamps();

            $table->unique(['fee_head_id', 'class_id', 'academic_year_id', 'group'], 'fee_rates_head_class_year_group_unique');
            $table->index(['class_id', 'academic_year_id']);
        });

        Schema::create('student_fee_waivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->foreignId('fee_head_id')->constrained('fee_heads')->restrictOnDelete();
            $table->decimal('percent', 5, 2)->nullable();
            $table->decimal('fixed_amount', 10, 2)->nullable();
            $table->string('reason')->nullable();
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'academic_year_id', 'fee_head_id'], 'student_fee_waivers_student_year_head_unique');
        });

        Schema::create('fee_dues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('enrolment_id')->constrained('student_enrolments')->restrictOnDelete();
            $table->foreignId('fee_head_id')->constrained('fee_heads')->restrictOnDelete();
            $table->string('period', 20); // YYYY-MM, one_time or exam:{id}
            $table->decimal('amount', 10, 2);
            $table->decimal('waiver_amount', 10, 2)->default(0);
            $table->decimal('net_amount', 10, 2);
            $table->decimal('paid_amount', 10, 2)->default(0);
            $table->string('status', 10)->default('unpaid'); // unpaid, partial, paid, waived
            $table->date('due_date');
            $table->timestamps();

            $table->unique(['enrolment_id', 'fee_head_id', 'period'], 'fee_dues_enrolment_head_period_unique');
            $table->index(['student_id', 'status']);
            $table->index('due_date');
        });

        Schema::create('fee_payments', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_no', 20)->unique();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->dateTime('paid_at');
            $table->string('method', 10); // cash, bkash, nagad, rocket
            $table->string('transaction_id', 64)->nullable();
            $table->decimal('amount', 10, 2);
            $table->foreignId('collected_by')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            // Nulls (cash) are distinct, so only mobile transaction IDs are constrained.
            $table->unique(['method', 'transaction_id'], 'fee_payments_method_transaction_unique');
            $table->index(['student_id', 'paid_at']);
            $table->index('paid_at');
        });

        Schema::create('fee_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_payment_id')->constrained('fee_payments')->restrictOnDelete();
            $table->foreignId('fee_due_id')->constrained('fee_dues')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->timestamps();

            $table->unique(['fee_payment_id', 'fee_due_id'], 'fee_payment_allocations_payment_due_unique');
        });

        // One row per calendar year; the last receipt number issued, locked FOR UPDATE.
        Schema::create('fee_receipt_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });

        $this->removeViewFeesFromStudentsAndGuardians();
    }

    public function down(): void
    {
        Schema::dropIfExists('fee_receipt_counters');
        Schema::dropIfExists('fee_payment_allocations');
        Schema::dropIfExists('fee_payments');
        Schema::dropIfExists('fee_dues');
        Schema::dropIfExists('student_fee_waivers');
        Schema::dropIfExists('fee_rates');
        Schema::dropIfExists('fee_heads');

        Schema::create('fee_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fee_structures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->enum('frequency', ['monthly', 'quarterly', 'half_yearly', 'yearly', 'one_time']);
            $table->date('due_date')->nullable();
            $table->timestamps();

            $table->unique(['fee_type_id', 'class_id', 'academic_year_id'], 'unique_fee_structure');
        });

        Schema::create('fee_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->foreignId('fee_structure_id')->constrained()->restrictOnDelete();
            $table->string('receipt_number')->unique();
            $table->decimal('amount_paid', 10, 2);
            $table->date('payment_date');
            $table->enum('payment_method', ['cash', 'cheque', 'online', 'card']);
            $table->string('transaction_id')->nullable();
            $table->enum('status', ['pending', 'paid', 'partial', 'overdue'])->default('paid');
            $table->text('remarks')->nullable();
            $table->foreignId('collected_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'payment_date']);
        });
    }

    /**
     * Students and guardians no longer hold view-fees (it lists every student's fees);
     * they read their own through /api/my/fees. RolePermissionSeeder does the same, but an
     * existing database may never re-run it.
     */
    private function removeViewFeesFromStudentsAndGuardians(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'view-fees')->value('id');
        $roleIds = DB::table('roles')->whereIn('name', ['student', 'parent'])->pluck('id');

        if ($permissionId && $roleIds->isNotEmpty()) {
            DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->whereIn('role_id', $roleIds)
                ->delete();
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
