<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every section belongs to a shift (required in validation; the FK is `restrictOnDelete`
 * so a shift in use can't be dropped from under a section) and may carry a group from
 * Class 9 (see App\Support\AcademicGroup). `shift_id` stays nullable in the database so
 * pre-existing rows survive the migration; it's backfilled with the lowest-id shift when
 * one exists. The unique key moves from (class_id, code) to (class_id, shift_id, code).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('shift_id')->nullable()->after('class_id')->constrained()->restrictOnDelete();
            $table->string('group')->nullable()->after('capacity');
        });

        $firstShiftId = DB::table('shifts')->orderBy('id')->value('id');

        if ($firstShiftId) {
            DB::table('sections')->whereNull('shift_id')->update(['shift_id' => $firstShiftId]);
        }

        Schema::table('sections', function (Blueprint $table) {
            // Add the new compound unique index before dropping the old one: MySQL
            // silently backs the class_id foreign key with whichever index starts with
            // class_id ("class_id, code" here, since no other index covered it), and
            // refuses to drop that index first ("needed in a foreign key constraint").
            // The new index also starts with class_id, so it can take over first.
            $table->unique(['class_id', 'shift_id', 'code']);
            $table->dropUnique(['class_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->unique(['class_id', 'code']);
            $table->dropUnique(['class_id', 'shift_id', 'code']);
        });

        Schema::table('sections', function (Blueprint $table) {
            $table->dropForeign(['shift_id']);
            $table->dropColumn(['shift_id', 'group']);
        });
    }
};
