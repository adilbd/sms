<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The first-generation exams, exam_schedules and exam_results tables were never used (the
 * controllers were stubs) and don't fit part-wise marks, so they are dropped here and
 * replaced by the exams, exam_subjects and exam_marks tables of the next migration. Task 3
 * recreates exam_results with its own shape. down() recreates the old structure without
 * data, children first, so a rollback past both migrations restores the original schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('exam_results');
        Schema::dropIfExists('exam_schedules');
        Schema::dropIfExists('exams');
    }

    public function down(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->foreignId('academic_year_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['term', 'unit', 'monthly', 'final']);
            $table->date('start_date');
            $table->date('end_date');
            $table->text('description')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['academic_year_id', 'type']);
        });

        Schema::create('exam_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->date('exam_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('room_number')->nullable();
            $table->integer('total_marks')->default(100);
            $table->integer('pass_marks')->default(40);
            $table->timestamps();

            $table->unique(['exam_id', 'class_id', 'section_id', 'subject_id'], 'unique_exam_schedule');
        });

        Schema::create('exam_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->decimal('marks_obtained', 5, 2);
            $table->decimal('marks_total', 5, 2);
            $table->string('grade')->nullable();
            $table->text('remarks')->nullable();
            $table->boolean('is_absent')->default(false);
            $table->foreignId('marked_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['exam_schedule_id', 'student_id']);
            $table->index('student_id');
        });
    }
};
