<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The certificate register and its serial counters. `data` is the JSON snapshot of every
 * printed field, taken at issue time. Cancelling replaces deleting, so there are no soft
 * deletes and a serial number is never reused. `certificate_counters` holds one row per
 * (type, Asia/Dhaka year), locked `for update` when a number is taken (like
 * `fee_receipt_counters`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('serial_no', 30)->unique();
            $table->foreignId('student_id')->constrained()->restrictOnDelete();
            $table->foreignId('enrolment_id')->nullable()->constrained('student_enrolments')->restrictOnDelete();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->date('issued_on');
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('data');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['type', 'issued_on']);
            $table->index('student_id');
        });

        Schema::create('certificate_counters', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);

            $table->unique(['type', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_counters');
        Schema::dropIfExists('certificates');
    }
};
