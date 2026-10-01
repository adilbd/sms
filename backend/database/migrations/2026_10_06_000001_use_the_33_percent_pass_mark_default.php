<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Bangladesh pass mark is 33%, but subjects.pass_marks defaulted to 40, which then
 * leaked into class_subjects.written_pass (backfill) and exam_subjects. A 33-39 mark (grade
 * D) was graded F. This changes the default to 33 and corrects rows still carrying the
 * legacy 100/40 written-only scheme. Custom pass marks (for example 17 of 50) are left alone,
 * and so are the exam_subjects of processed or published exams: those are snapshots of what
 * the results were graded with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->integer('pass_marks')->default(33)->change();
        });

        $this->fixLegacyPassMarks();
    }

    /**
     * Only the default is restored. The data fix is not reversed: after it a legitimate 33
     * cannot be told apart from a corrected 40, so turning every 33 back into 40 would
     * corrupt rows that were always 33.
     */
    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->integer('pass_marks')->default(40)->change();
        });
    }

    /** Its own method so the migration test can run it against rows it creates. */
    private function fixLegacyPassMarks(): void
    {
        DB::table('subjects')->where('total_marks', 100)->where('pass_marks', 40)->update(['pass_marks' => 33]);

        DB::table('class_subjects')
            ->where('written_full', 100)->where('written_pass', 40)
            ->whereNull('mcq_full')->whereNull('practical_full')
            ->update(['written_pass' => 33]);

        DB::table('exam_subjects')
            ->where('written_full', 100)->where('written_pass', 40)
            ->whereNull('mcq_full')->whereNull('practical_full')
            ->whereIn('exam_id', DB::table('exams')->whereIn('status', ['draft', 'marks_entry'])->select('id'))
            ->update(['written_pass' => 33]);
    }
};
