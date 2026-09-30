<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the calendar `year` (2000-2100) that `start_date`/`end_date` must fall inside
 * (see App\Services\AcademicYearService). Stays nullable in the database so any
 * pre-existing rows survive the migration; backfilled from `start_date`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->nullable()->after('id');
        });

        foreach (DB::table('academic_years')->whereNull('year')->get(['id', 'start_date']) as $academicYear) {
            DB::table('academic_years')
                ->where('id', $academicYear->id)
                ->update(['year' => (int) date('Y', strtotime($academicYear->start_date))]);
        }

        Schema::table('academic_years', function (Blueprint $table) {
            $table->unique('year');
        });
    }

    public function down(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropUnique(['year']);
            $table->dropColumn('year');
        });
    }
};
