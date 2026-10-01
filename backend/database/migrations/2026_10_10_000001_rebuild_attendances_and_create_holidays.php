<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daily attendance by section, linked to the student's yearly enrolment, and the school's
 * listed holidays. The legacy attendances table (per student and class, with half_day and
 * holiday statuses) never held real data, so it is dropped and recreated; down() restores
 * the old structure, without data. Dates are Asia/Dhaka calendar dates (date columns, no
 * time zone). See App\Services\AttendanceService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('attendances');

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrolment_id')->constrained('student_enrolments')->cascadeOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('status', 10);
            $table->string('remarks')->nullable();
            $table->foreignId('marked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['enrolment_id', 'date']);
            $table->index(['section_id', 'date']);
        });

        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name_en')->nullable();
            $table->string('name_bn')->nullable();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('attendances');

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->enum('status', ['present', 'absent', 'late', 'half_day', 'holiday']);
            $table->text('remarks')->nullable();
            $table->foreignId('marked_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['student_id', 'date']);
            $table->index(['date', 'class_id', 'section_id']);
        });
    }
};
