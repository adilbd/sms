<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The curriculum: which subjects each class studies, optionally per group (Class 9+) and
 * compulsory or optional (a 4th-subject choice). A pivot-style table, so no soft
 * deletes. `group` null means the whole class (all groups from Class 9). Uniqueness of
 * (class, subject, group) is enforced in App\Services\CurriculumService, because a
 * unique index treats nulls as distinct on both MySQL and SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained('subjects')->restrictOnDelete();
            $table->string('group')->nullable();
            $table->string('type')->default('compulsory'); // compulsory, optional
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['class_id', 'group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_subjects');
    }
};
