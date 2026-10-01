<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How each curriculum row is marked: up to three parts (written, MCQ, practical), each
 * with a full and a pass mark, plus an optional paper group that pairs two rows (Bangla
 * 1st + 2nd Paper) into one graded subject. Existing rows get their subject's total and
 * pass marks as the written part. The rules between the columns live in
 * App\Services\CurriculumService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_subjects', function (Blueprint $table) {
            $table->unsignedSmallInteger('written_full')->nullable()->after('type');
            $table->unsignedSmallInteger('written_pass')->nullable()->after('written_full');
            $table->unsignedSmallInteger('mcq_full')->nullable()->after('written_pass');
            $table->unsignedSmallInteger('mcq_pass')->nullable()->after('mcq_full');
            $table->unsignedSmallInteger('practical_full')->nullable()->after('mcq_pass');
            $table->unsignedSmallInteger('practical_pass')->nullable()->after('practical_full');
            $table->string('paper_group', 50)->nullable()->after('practical_pass');
        });

        $this->backfillWrittenMarks();
    }

    /**
     * A correlated subquery, so the same statement runs on MySQL and SQLite. Its own
     * method so the migration test can run it against rows it creates.
     */
    private function backfillWrittenMarks(): void
    {
        DB::statement('UPDATE class_subjects SET
            written_full = (SELECT total_marks FROM subjects WHERE subjects.id = class_subjects.subject_id),
            written_pass = (SELECT pass_marks FROM subjects WHERE subjects.id = class_subjects.subject_id)');
    }

    public function down(): void
    {
        Schema::table('class_subjects', function (Blueprint $table) {
            $table->dropColumn([
                'written_full', 'written_pass', 'mcq_full', 'mcq_pass',
                'practical_full', 'practical_pass', 'paper_group',
            ]);
        });
    }
};
