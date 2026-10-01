<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A failed 4th subject must not count against a student, so passed_count now counts
 * compulsory units only (matching failed_count). This recomputes it for existing rows from
 * the `subjects` JSON: units that are not `is_optional` and whose grade is not F (a combined
 * pair is one unit). Done in PHP, in chunks, so it behaves the same on SQLite and MySQL.
 * Positions already stored are not recomputed: reprocess an exam to rank it by the new count.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('exam_results')->select('id', 'subjects')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $units = json_decode($row->subjects, true) ?: [];
                $passed = count(array_filter($units, fn ($unit) => is_array($unit)
                    && empty($unit['is_optional'])
                    && ($unit['grade'] ?? 'F') !== 'F'));

                DB::table('exam_results')->where('id', $row->id)->update(['passed_count' => $passed]);
            }
        });
    }

    public function down(): void
    {
        // The previous count (the 4th subject included) is not worth reconstructing.
    }
};
