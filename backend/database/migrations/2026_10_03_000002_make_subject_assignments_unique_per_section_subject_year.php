<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One teacher per subject in a section per academic year: the unique key goes from the
 * 5-column (staff, subject, class, section, year) to (section, subject, year).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Nothing wrote to this table before the module existed, but a duplicate would
        // make the new index fail, so keep the oldest row of each (section, subject, year).
        $keep = DB::table('subject_assignments')
            ->selectRaw('MIN(id) as id')
            ->groupBy('section_id', 'subject_id', 'academic_year_id')
            ->pluck('id');

        DB::table('subject_assignments')->whereNotIn('id', $keep)->delete();

        Schema::table('subject_assignments', function (Blueprint $table) {
            // On MySQL staff_id's foreign key relies on the old unique index (it is the
            // only index starting with staff_id), so the constraint goes first and is
            // re-added afterwards with an index of its own.
            $table->dropForeign(['staff_id']);
            $table->dropUnique('unique_subject_assignment');
        });

        Schema::table('subject_assignments', function (Blueprint $table) {
            // Named explicitly: the default name is 65 characters, over MySQL's 64 limit.
            $table->unique(['section_id', 'subject_id', 'academic_year_id'], 'subject_assignments_section_subject_year_unique');
            $table->foreign('staff_id')->references('id')->on('staff')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('subject_assignments', function (Blueprint $table) {
            // MySQL dropped section_id's own index when the new unique key (which starts
            // with section_id) was added, so that key now backs its foreign key: the
            // constraint goes first and is re-added afterwards.
            $table->dropForeign(['section_id']);
            $table->dropUnique('subject_assignments_section_subject_year_unique');
        });

        Schema::table('subject_assignments', function (Blueprint $table) {
            $table->unique(['staff_id', 'subject_id', 'class_id', 'section_id', 'academic_year_id'], 'unique_subject_assignment');
            $table->foreign('section_id')->references('id')->on('sections')->cascadeOnDelete();
        });
    }
};
