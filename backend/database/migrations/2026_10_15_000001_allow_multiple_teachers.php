<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Several class teachers per section and year (exactly one `is_main`, enforced in
 * App\Services\ClassTeacherService because a partial unique index isn't portable), a
 * teacher leading several sections, and several teachers per (section, subject, year).
 *
 * class_sections: the unique keys (section, year) and (year, staff) go; (section, year,
 * staff) replaces them and every existing row becomes the main teacher.
 * subject_assignments: the unique key gains staff_id.
 *
 * down() restores the old, stricter unique keys. It FAILS with a unique-constraint error
 * while the data holds more than one class teacher per section/year, a teacher leading
 * several sections in a year, or several teachers on one (section, subject, year). Remove
 * the extra rows first, then roll back (MySQL DDL isn't transactional, so check the data
 * beforehand rather than relying on a failed rollback leaving nothing behind).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sections', function (Blueprint $table) {
            $table->boolean('is_main')->default(false)->after('staff_id');
        });

        DB::table('class_sections')->update(['is_main' => true]);

        Schema::table('class_sections', function (Blueprint $table) {
            // On MySQL the foreign keys on section_id and academic_year_id lean on the old
            // unique keys. The new unique key starts with section_id (so it backs that one);
            // academic_year_id gets a dedicated index, both added before the old keys go.
            $table->unique(['section_id', 'academic_year_id', 'staff_id'], 'class_sections_section_year_staff_unique');
            $table->index('academic_year_id', 'class_sections_academic_year_id_index');
        });

        Schema::table('class_sections', function (Blueprint $table) {
            $table->dropUnique(['section_id', 'academic_year_id']);
            $table->dropUnique(['academic_year_id', 'staff_id']);
        });

        // Added first: the section_id foreign key keeps the new key to lean on.
        Schema::table('subject_assignments', function (Blueprint $table) {
            $table->unique(['section_id', 'subject_id', 'academic_year_id', 'staff_id'], 'subject_assignments_section_subject_year_staff_unique');
        });

        Schema::table('subject_assignments', function (Blueprint $table) {
            $table->dropUnique('subject_assignments_section_subject_year_unique');
        });
    }

    public function down(): void
    {
        // Throws a unique-constraint error when duplicates exist (see the class comment).
        Schema::table('subject_assignments', function (Blueprint $table) {
            $table->unique(['section_id', 'subject_id', 'academic_year_id'], 'subject_assignments_section_subject_year_unique');
        });

        Schema::table('subject_assignments', function (Blueprint $table) {
            $table->dropUnique('subject_assignments_section_subject_year_staff_unique');
        });

        Schema::table('class_sections', function (Blueprint $table) {
            $table->unique(['section_id', 'academic_year_id']);
            $table->unique(['academic_year_id', 'staff_id']);
        });

        Schema::table('class_sections', function (Blueprint $table) {
            $table->dropUnique('class_sections_section_year_staff_unique');
            $table->dropIndex('class_sections_academic_year_id_index');
            $table->dropColumn('is_main');
        });
    }
};
