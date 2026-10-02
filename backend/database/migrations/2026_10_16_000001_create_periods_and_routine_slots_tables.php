<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The class routine. `periods` are the bell schedule of one shift (Asia/Dhaka wall-clock
 * times, like `shifts`); `routine_slots` are the cells of a section's weekly grid.
 *
 * `room_key` is the room name trimmed, whitespace-collapsed and lowercased by
 * App\Services\RoutineService, so a room clash is a plain equality check on every database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('periods', function (Blueprint $table) {
            $table->id();
            // Periods go with their shift; a shift in use by sections can't be deleted anyway.
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('name_en');
            $table->string('name_bn');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('is_break')->default(false);
            $table->timestamps();

            $table->unique(['shift_id', 'number']);
        });

        Schema::create('routine_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->string('day', 10);
            $table->foreignId('period_id')->constrained()->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->string('room', 50)->nullable();
            $table->string('room_key', 50)->nullable();
            $table->timestamps();

            $table->unique(['academic_year_id', 'section_id', 'day', 'period_id'], 'routine_slots_year_section_day_period_unique');
            $table->index(['academic_year_id', 'day', 'staff_id'], 'routine_slots_year_day_staff_index');
            $table->index(['academic_year_id', 'day', 'room_key'], 'routine_slots_year_day_room_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routine_slots');
        Schema::dropIfExists('periods');
    }
};
