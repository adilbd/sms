<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repoints subject_assignments at the new staff table and drops the placeholder
 * teachers table (see the Staff module note in CLAUDE.md). Nothing writes to
 * teachers or subject_assignments.teacher_id yet, so there is no data to migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subject_assignments', function (Blueprint $table) {
            $table->dropUnique('unique_subject_assignment');
            $table->dropForeign(['teacher_id']);
            $table->dropColumn('teacher_id');
        });

        Schema::table('subject_assignments', function (Blueprint $table) {
            $table->foreignId('staff_id')->after('id')->constrained('staff')->cascadeOnDelete();
            $table->unique(['staff_id', 'subject_id', 'class_id', 'section_id', 'academic_year_id'], 'unique_subject_assignment');
        });

        Schema::dropIfExists('teachers');
    }

    public function down(): void
    {
        Schema::create('teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('employee_id')->unique();
            $table->date('joining_date');
            $table->date('date_of_birth');
            $table->enum('gender', ['male', 'female', 'other']);
            $table->enum('blood_group', ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'])->nullable();
            $table->string('qualification');
            $table->string('designation');
            $table->string('department')->nullable();
            $table->decimal('salary', 10, 2)->nullable();
            $table->text('address');
            $table->string('city');
            $table->string('state');
            $table->string('country')->default('India');
            $table->string('pincode');
            $table->string('photo')->nullable();
            $table->text('documents')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('employee_id');
        });

        Schema::table('subject_assignments', function (Blueprint $table) {
            $table->dropUnique('unique_subject_assignment');
            $table->dropForeign(['staff_id']);
            $table->dropColumn('staff_id');
        });

        Schema::table('subject_assignments', function (Blueprint $table) {
            $table->foreignId('teacher_id')->after('id')->constrained()->cascadeOnDelete();
            $table->unique(['teacher_id', 'subject_id', 'class_id', 'section_id', 'academic_year_id'], 'unique_subject_assignment');
        });
    }
};
