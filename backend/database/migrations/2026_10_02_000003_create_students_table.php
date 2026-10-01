<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Bangladeshi student profile, with the guardian's details on the student. The
 * class/section/group/roll of each year live in student_enrolments. See
 * docs/tasks/students-module.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();
            $table->string('student_id')->unique();
            $table->string('name_en')->nullable();
            $table->string('name_bn')->nullable();

            $table->date('date_of_birth');
            $table->string('gender', 20);
            $table->string('religion', 30)->nullable();
            $table->string('blood_group', 5)->nullable();
            $table->string('birth_registration_number', 17)->nullable()->unique();
            $table->string('nationality', 100)->default('Bangladeshi');

            $table->string('mobile', 30)->nullable();
            $table->string('email')->nullable();

            $table->text('present_address')->nullable();
            $table->text('permanent_address')->nullable();
            $table->string('district', 100)->nullable();
            $table->string('photo')->nullable();

            $table->date('admission_date');
            $table->string('status', 20)->default('active');
            $table->date('leaving_date')->nullable();

            $table->string('father_name_en')->nullable();
            $table->string('father_name_bn')->nullable();
            $table->string('father_mobile', 30)->nullable();
            $table->string('father_occupation')->nullable();
            $table->string('mother_name_en')->nullable();
            $table->string('mother_name_bn')->nullable();
            $table->string('mother_mobile', 30)->nullable();
            $table->string('mother_occupation')->nullable();

            $table->string('guardian_relation', 20);
            $table->string('guardian_name');
            $table->string('guardian_mobile', 30);
            $table->foreignId('guardian_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('guardian_mobile');
            $table->index('status');
        });

        foreach (['attendances', 'exam_results', 'fee_payments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['attendances', 'exam_results', 'fee_payments'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['student_id']);
            });
        }

        Schema::dropIfExists('students');
    }
};
