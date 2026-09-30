<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repoints class_sections.class_teacher_id (users) at staff, and tightens the unique
 * keys: one class teacher per section per year, and one section per teacher per year
 * (see App\Services\ClassTeacherService). Nothing writes to class_teacher_id yet, so
 * there is no data to migrate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sections', function (Blueprint $table) {
            $table->dropForeign(['class_teacher_id']);
            $table->dropColumn('class_teacher_id');
        });

        Schema::table('class_sections', function (Blueprint $table) {
            // The class_id foreign key is backed by this compound unique index (the
            // leftmost prefix), since no other index on class_id alone exists. MySQL
            // refuses to drop it while the FK still relies on it, so add a dedicated
            // single-column index first for the FK to fall back on.
            $table->index('class_id');
            $table->dropUnique(['class_id', 'section_id', 'academic_year_id']);
            $table->foreignId('staff_id')->nullable()->after('academic_year_id')->constrained('staff')->restrictOnDelete();
            $table->unique(['section_id', 'academic_year_id']);
            $table->unique(['academic_year_id', 'staff_id']);
        });

        // Symmetric with down(), which restores these single-column FK-backing indexes:
        // drop them if a previous rollback left them behind (the new compound uniques
        // now back those foreign keys), so re-applying never accumulates them.
        Schema::table('class_sections', function (Blueprint $table) {
            foreach (['section_id', 'academic_year_id'] as $column) {
                if (Schema::hasIndex('class_sections', "class_sections_{$column}_index")) {
                    $table->dropIndex("class_sections_{$column}_index");
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('class_sections', function (Blueprint $table) {
            // up() made the auto-generated single-column indexes that originally backed
            // section_id's and academic_year_id's own foreign keys (to sections and
            // academic_years) redundant, and MySQL silently dropped them in favor of the
            // new compound uniques. Restore dedicated indexes before dropping those
            // uniques, so those two (unrelated) foreign keys stay satisfied.
            foreach (['section_id', 'academic_year_id'] as $column) {
                if (! Schema::hasIndex('class_sections', "class_sections_{$column}_index")) {
                    $table->index($column, "class_sections_{$column}_index");
                }
            }
            $table->dropUnique(['section_id', 'academic_year_id']);
            $table->dropUnique(['academic_year_id', 'staff_id']);
            $table->dropForeign(['staff_id']);
            $table->dropColumn('staff_id');
        });

        Schema::table('class_sections', function (Blueprint $table) {
            $table->foreignId('class_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unique(['class_id', 'section_id', 'academic_year_id']);
        });

        // Drop the class_id helper index added in up(): the compound unique just
        // re-added covers class_id again (its leftmost prefix). section_id's and
        // academic_year_id's helper indexes (added above) still each need to stay,
        // same as the original schema's dedicated foreign-key indexes. Explicit,
        // since (unlike the auto-generated indexes InnoDB creates for a bare foreign
        // key) this one was added by name and MySQL won't drop it on its own; leaving
        // it would make a second rollback-and-reapply cycle fail with a duplicate key
        // name.
        Schema::table('class_sections', function (Blueprint $table) {
            $table->dropIndex(['class_id']);
        });
    }
};
