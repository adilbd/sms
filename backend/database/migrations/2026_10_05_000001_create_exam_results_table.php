<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Processed exam results: one row per exam and enrolment, written (and replaced) by
 * App\Services\ResultService when an admin processes the exam. `subjects` is the JSON
 * breakdown, one entry per graded unit (a subject, or a pair of papers combined), so a
 * published result stays as it was graded even if the curriculum or marks scheme changes.
 * class_position and section_position are standard competition ranks (see App\Support\Gpa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrolment_id')->constrained('student_enrolments')->restrictOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('section_id')->constrained('sections')->restrictOnDelete();
            $table->decimal('total_obtained', 7, 2)->default(0);
            $table->decimal('total_full', 7, 2)->default(0);
            $table->decimal('gpa', 3, 2)->default(0);
            $table->string('grade', 2)->default('F');
            $table->boolean('is_pass')->default(false);
            $table->unsignedSmallInteger('failed_count')->default(0);
            $table->unsignedInteger('class_position')->nullable();
            $table->unsignedInteger('section_position')->nullable();
            $table->json('subjects');
            $table->timestamps();

            $table->unique(['exam_id', 'enrolment_id']);
            $table->index(['exam_id', 'class_id', 'class_position']);
            $table->index(['exam_id', 'section_id', 'section_position']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_results');
    }
};
