<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One enrolment per student per academic year: section (class is taken from it), group
 * from Class 9, the 4th subject and the roll number. See App\Services\EnrolmentService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_enrolments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('class_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->string('group', 30)->nullable();
            $table->foreignId('optional_subject_id')->nullable()->constrained('subjects')->restrictOnDelete();
            $table->unsignedSmallInteger('roll_number')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['student_id', 'academic_year_id']);
            // NULL roll numbers never collide; the service checks first and this closes
            // the concurrent-request gap.
            $table->unique(['section_id', 'academic_year_id', 'roll_number'], 'student_enrolments_section_year_roll_unique');
            $table->index(['academic_year_id', 'class_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrolments');
    }
};
