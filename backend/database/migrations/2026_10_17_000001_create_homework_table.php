<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Homework a subject teacher (or an admin) assigns to a section. `assigned_on` and `due_on`
 * are Asia/Dhaka calendar dates; `attachment_path` is a file on the private disk.
 * `staff_id` is the author (null for an admin with no staff row).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->string('title');
            $table->text('details')->nullable();
            $table->date('assigned_on');
            $table->date('due_on');
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['section_id', 'academic_year_id', 'due_on'], 'homework_section_year_due_index');
            $table->index('staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework');
    }
};
