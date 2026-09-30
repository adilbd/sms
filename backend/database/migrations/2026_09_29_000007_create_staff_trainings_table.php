<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('title');
            $table->string('organizer')->nullable();
            $table->string('duration')->nullable();
            $table->string('year')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['staff_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_trainings');
    }
};
