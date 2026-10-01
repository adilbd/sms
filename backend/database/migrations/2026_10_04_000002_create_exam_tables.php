<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Exams, their per-class subject schedule and the marks entered against it.
 *
 * exam_subjects is a snapshot of the class's curriculum taken when the schedule is
 * generated (group, type, paper_group and the six part marks), so later curriculum edits
 * don't change a past exam. Its unique key includes `group` because a curriculum may list
 * the same subject for several groups (General Science for Business Studies and Humanities,
 * Agriculture as the optional subject of all three); a null group is a distinct value in a
 * unique index, so ExamService also guarantees one common row per subject. Dates and times
 * are Asia/Dhaka wall-clock values (like shifts), not UTC. See App\Services\ExamService
 * and App\Services\ExamMarkService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->string('name_en')->nullable();
            $table->string('name_bn')->nullable();
            $table->string('code', 50);
            $table->string('type', 20);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['academic_year_id', 'code']);
            $table->index(['academic_year_id', 'type']);
        });

        Schema::create('exam_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_id')->constrained('classes')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->string('group', 30)->nullable();
            $table->string('type', 20)->default('compulsory');
            $table->string('paper_group', 50)->nullable();
            $table->unsignedSmallInteger('written_full')->nullable();
            $table->unsignedSmallInteger('written_pass')->nullable();
            $table->unsignedSmallInteger('mcq_full')->nullable();
            $table->unsignedSmallInteger('mcq_pass')->nullable();
            $table->unsignedSmallInteger('practical_full')->nullable();
            $table->unsignedSmallInteger('practical_pass')->nullable();
            $table->date('exam_date')->nullable();
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['exam_id', 'class_id', 'subject_id', 'group'], 'exam_subjects_exam_class_subject_group_unique');
            $table->index(['class_id', 'subject_id']);
        });

        Schema::create('exam_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('exam_subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrolment_id')->constrained('student_enrolments')->restrictOnDelete();
            $table->decimal('written', 5, 2)->nullable();
            $table->decimal('mcq', 5, 2)->nullable();
            $table->decimal('practical', 5, 2)->nullable();
            $table->boolean('is_absent')->default(false);
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['exam_subject_id', 'student_id']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_marks');
        Schema::dropIfExists('exam_subjects');
        Schema::dropIfExists('exams');
    }
};
