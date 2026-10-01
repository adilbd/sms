<?php

use App\Support\EnrolmentStart;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The day a student's enrolment in the academic year began (an Asia/Dhaka calendar date),
 * so attendance does not count school days before a mid-year joiner arrived. Null means
 * "from the start of the year". Existing rows are backfilled with the student's admission
 * date, raised to the academic year's start and capped at its end (App\Support\EnrolmentStart;
 * `created_at` is data-entry time, not the joining date). Done in PHP, not SQL, so it behaves the
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
            ->join('students', 'students.id', '=', 'student_enrolments.student_id')
            ->orderBy('student_enrolments.id')
            ->select('student_enrolments.id', 'students.admission_date', 'academic_years.start_date', 'academic_years.end_date')
            ->chunk(500, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('student_enrolments')->where('id', $row->id)->update([
                        'enrolled_on' => EnrolmentStart::for($row->start_date, $row->end_date, $row->admission_date),
                    ]);
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
