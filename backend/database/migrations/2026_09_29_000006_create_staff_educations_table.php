<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_educations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('degree');
            $table->string('institution')->nullable();
            $table->string('board_university')->nullable();
            $table->string('passing_year')->nullable();
            $table->string('result')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['staff_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_educations');
    }
};
