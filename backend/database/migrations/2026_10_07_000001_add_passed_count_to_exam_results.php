<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merit positions rank by GPA, then subjects passed, then total (see App\Support\Gpa).
 * passed_count is the number of graded units with a grade other than F (the 4th subject
 * included, a combined pair counted once). Existing rows are backfilled from the `subjects`
 * JSON in PHP, in chunks, so it behaves the same on SQLite and MySQL. Positions already
 * stored are not recomputed: reprocess an exam to rank it by the new order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exam_results', function (Blueprint $table) {
            $table->unsignedSmallInteger('passed_count')->default(0)->after('failed_count');
        });

        DB::table('exam_results')->select('id', 'subjects')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $units = json_decode($row->subjects, true) ?: [];
                $passed = count(array_filter($units, fn ($unit) => is_array($unit) && ($unit['grade'] ?? 'F') !== 'F'));

                DB::table('exam_results')->where('id', $row->id)->update(['passed_count' => $passed]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('exam_results', function (Blueprint $table) {
            $table->dropColumn('passed_count');
        });
    }
};
