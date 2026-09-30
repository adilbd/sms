<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the Class 1-12 `number` (from which the level and has_groups are derived, see
 * App\Models\Classes) and an optional `name_bn`. `number` stays nullable in the database
 * so any pre-existing rows survive the migration; StoreClassRequest requires it for new
 * rows going forward. Existing rows are best-effort backfilled from a digit in `name`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->unsignedTinyInteger('number')->nullable()->after('id');
            $table->string('name_bn')->nullable()->after('name');
        });

        // Best-effort backfill for any rows that predate this column: a name like
        // "Class 5" or "Five" unambiguously contains a single 1-12 number.
        foreach (DB::table('classes')->whereNull('number')->get(['id', 'name']) as $class) {
            if (preg_match('/\b(1[0-2]|[1-9])\b/', (string) $class->name, $matches) === 1) {
                DB::table('classes')->where('id', $class->id)->update(['number' => (int) $matches[1]]);
            }
        }

        Schema::table('classes', function (Blueprint $table) {
            $table->unique('number');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropColumn(['number', 'name_bn']);
        });
    }
};
