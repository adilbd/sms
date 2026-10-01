<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The day a student's enrolment in the academic year began (an Asia/Dhaka calendar date),
 * so attendance does not count school days before a mid-year joiner arrived. Null means
 * "from the start of the year". Existing rows are backfilled with the Asia/Dhaka date of
 * `created_at`, or the academic year's start date if that is later (an enrolment made in
 * advance for a coming year starts with the year). Done in PHP, not SQL, so it behaves the
 * same on SQLite and MySQL. See App\Services\EnrolmentService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_enrolments', function (Blueprint $table) {
            $table->date('enrolled_on')->nullable()->after('roll_number');
        });

        DB::table('student_enrolments')
            ->join('academic_years', 'academic_years.id', '=', 'student_enrolments.academic_year_id')
            ->orderBy('student_enrolments.id')
            ->select('student_enrolments.id', 'student_enrolments.created_at', 'academic_years.start_date')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    $start = CarbonImmutable::parse($row->start_date)->toDateString();
                    $created = $row->created_at
                        ? CarbonImmutable::parse($row->created_at, 'UTC')->setTimezone('Asia/Dhaka')->toDateString()
                        : $start;

                    DB::table('student_enrolments')->where('id', $row->id)->update(['enrolled_on' => max($created, $start)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('student_enrolments', function (Blueprint $table) {
            $table->dropColumn('enrolled_on');
        });
    }
};
