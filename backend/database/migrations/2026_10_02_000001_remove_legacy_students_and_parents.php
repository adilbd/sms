<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Throws away the legacy Indian-style students, the never-used parents/parent_student
 * tables, and every student/parent login, so the Students module can start from a clean
 * schema (see docs/tasks/students-module.md). The rows that pointed at students
 * (attendances, exam_results, fee_payments) were legacy or test data and are deleted too.
 *
 * down() recreates the OLD tables' structure only. It does not bring any data back.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Rows that reference students go first, then the logins, then the tables.
        foreach (['attendances', 'exam_results', 'fee_payments'] as $table) {
            DB::table($table)->whereNotNull('student_id')->delete();
        }

        $this->deleteUsersWithRoles(['student', 'parent']);

        foreach (['attendances', 'exam_results', 'fee_payments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['student_id']);
            });
        }

        $this->removeLegacyPermissions();

        Schema::dropIfExists('parent_student');
        Schema::dropIfExists('parents');
        Schema::dropIfExists('students');
    }

    public function down(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('admission_number')->unique();
            $table->string('roll_number')->nullable();
            $table->foreignId('class_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->date('admission_date');
            $table->date('date_of_birth');
            $table->enum('gender', ['male', 'female', 'other']);
            $table->enum('blood_group', ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'])->nullable();
            $table->string('religion')->nullable();
            $table->string('caste')->nullable();
            $table->string('category')->nullable();
            $table->string('mother_tongue')->nullable();
            $table->text('address');
            $table->string('city');
            $table->string('state');
            $table->string('country')->default('India');
            $table->string('pincode');
            $table->string('photo')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['class_id', 'section_id', 'academic_year_id']);
        });

        Schema::create('parents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('father_name');
            $table->string('father_phone')->nullable();
            $table->string('father_email')->nullable();
            $table->string('father_occupation')->nullable();
            $table->string('mother_name');
            $table->string('mother_phone')->nullable();
            $table->string('mother_email')->nullable();
            $table->string('mother_occupation')->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone')->nullable();
            $table->string('guardian_email')->nullable();
            $table->string('guardian_relation')->nullable();
            $table->text('address');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('parent_student', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('relation');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['parent_id', 'student_id']);
        });

        foreach (['attendances', 'exam_results', 'fee_payments'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            });
        }
    }

    /**
     * Guardians no longer list students (they read their own children through
     * /api/my/children), and the parents permissions went with the parents stub. The
     * seeder does the same, but existing databases may never re-run it.
     */
    private function removeLegacyPermissions(): void
    {
        $parentsPermissionIds = DB::table('permissions')->where('name', 'like', '%-parents')->pluck('id');
        $viewStudentsId = DB::table('permissions')->where('name', 'view-students')->value('id');
        $parentRoleId = DB::table('roles')->where('name', 'parent')->value('id');

        if ($viewStudentsId && $parentRoleId) {
            DB::table('role_has_permissions')->where('role_id', $parentRoleId)->where('permission_id', $viewStudentsId)->delete();
        }

        if ($parentsPermissionIds->isNotEmpty()) {
            DB::table('role_has_permissions')->whereIn('permission_id', $parentsPermissionIds)->delete();
            DB::table('model_has_permissions')->whereIn('permission_id', $parentsPermissionIds)->delete();
            DB::table('permissions')->whereIn('id', $parentsPermissionIds)->delete();
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @param  list<string>  $roles
     */
    private function deleteUsersWithRoles(array $roles): void
    {
        $roleIds = DB::table('roles')->whereIn('name', $roles)->pluck('id');

        if ($roleIds->isEmpty()) {
            return;
        }

        $userIds = DB::table('model_has_roles')
            ->where('model_type', \App\Models\User::class)
            ->whereIn('role_id', $roleIds)
            ->pluck('model_id')
            ->all();

        foreach (array_chunk($userIds, 500) as $chunk) {
            DB::table('personal_access_tokens')
                ->where('tokenable_type', \App\Models\User::class)
                ->whereIn('tokenable_id', $chunk)
                ->delete();
            DB::table('model_has_roles')->where('model_type', \App\Models\User::class)->whereIn('model_id', $chunk)->delete();
            DB::table('model_has_permissions')->where('model_type', \App\Models\User::class)->whereIn('model_id', $chunk)->delete();
            DB::table('users')->whereIn('id', $chunk)->delete();
        }
    }
};
