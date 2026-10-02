<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Online admission: rounds (per academic year) with the classes and seats on offer, the
 * applications families submit, and a per-year counter for application numbers
 * (`ADM-{year}-{000001}`, issued like fee receipts). See docs/tasks/online-admission.md.
 *
 * Index names are given explicitly where Laravel's generated one would pass MySQL's
 * 64-character limit. down() drops the tables children first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained('academic_years')->restrictOnDelete();
            $table->string('name_en')->nullable();
            $table->string('name_bn')->nullable();
            $table->date('opens_at');
            $table->date('closes_at');
            $table->boolean('is_published')->default(false);
            $table->text('instructions_bn')->nullable();
            $table->text('instructions_en')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_published', 'opens_at', 'closes_at']);
        });

        Schema::create('admission_round_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained('admission_rounds')->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->unsignedInteger('seats')->nullable();
            $table->timestamps();

            $table->unique(['round_id', 'class_id']);
        });

        Schema::create('admission_applications', function (Blueprint $table) {
            $table->id();
            $table->string('application_no', 30)->unique();
            $table->foreignId('round_id')->constrained('admission_rounds')->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->string('group', 30)->nullable();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->restrictOnDelete();

            $table->string('name_en')->nullable();
            $table->string('name_bn')->nullable();
            $table->date('date_of_birth');
            $table->string('gender', 20);
            $table->string('religion', 30)->nullable();
            $table->string('birth_registration_number', 17);
            $table->string('blood_group', 5)->nullable();
            $table->string('nationality', 100)->default('Bangladeshi');

            $table->string('previous_school')->nullable();
            $table->string('previous_class', 100)->nullable();

            $table->string('father_name_en')->nullable();
            $table->string('father_name_bn')->nullable();
            $table->string('father_mobile', 30)->nullable();
            $table->string('mother_name_en')->nullable();
            $table->string('mother_name_bn')->nullable();
            $table->string('mother_mobile', 30)->nullable();
            $table->string('guardian_relation', 20);
            $table->string('guardian_name');
            $table->string('guardian_mobile', 30);
            $table->string('guardian_email')->nullable();

            $table->text('present_address');
            $table->text('permanent_address')->nullable();
            $table->string('district', 100)->nullable();

            // Private disk paths (admissions/{uuid}.{ext}); never public URLs.
            $table->string('photo_path');
            $table->string('birth_certificate_path')->nullable();
            $table->string('previous_school_doc_path')->nullable();

            $table->string('status', 20)->default('submitted');
            $table->timestamp('test_at')->nullable();
            $table->string('test_venue')->nullable();
            $table->decimal('test_score', 6, 2)->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->string('submitted_ip_hash', 40)->nullable();

            $table->timestamps();
            $table->softDeletes();

            // One application per child per round.
            $table->unique(['round_id', 'birth_registration_number'], 'admission_apps_round_birth_reg_unique');
            $table->index(['round_id', 'class_id', 'status']);
            $table->index('guardian_mobile');
        });

        // One row per year; the last application number issued, locked FOR UPDATE.
        Schema::create('admission_application_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_application_counters');
        Schema::dropIfExists('admission_applications');
        Schema::dropIfExists('admission_round_classes');
        Schema::dropIfExists('admission_rounds');
    }
};
