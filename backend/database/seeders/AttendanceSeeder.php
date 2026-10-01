<?php

namespace Database\Seeders;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\Holiday;
use App\Models\Section;
use App\Models\StudentEnrolment;
use App\Models\User;
use App\Services\InstituteSettingsService;
use App\Support\SchoolDays;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo attendance for the last 5 school days (up to today in Asia/Dhaka, inside the 2026
 * academic year) of every seeded Section A, with some absences, late marks and leave. The
 * status of a student on a day comes from a hash of the two, so a re-run writes the same
 * values; rows are matched by (enrolment, date), so it never duplicates. Needs the
 * Role/AcademicYear/Holiday/Section/Student seeders first.
 */
class AttendanceSeeder extends Seeder
{
    private const DAYS = 5;

    public function run(InstituteSettingsService $institute): void
    {
        $year = AcademicYear::where('year', 2026)->first();

        if (! $year) {
            return;
        }

        $days = $this->lastSchoolDays($year, $institute->weeklyHolidays());

        if ($days === []) {
            return;
        }

        $markedBy = User::where('email', 'admin@sms.com')->value('id');

        $enrolments = StudentEnrolment::query()
            ->activeIn($year->id)
            ->whereIn('section_id', Section::where('code', 'A')->pluck('id'))
            ->get();

        foreach ($enrolments as $enrolment) {
            foreach ($days as $date) {
                $status = $this->statusFor($enrolment->id, $date);

                Attendance::updateOrCreate(
                    ['enrolment_id' => $enrolment->id, 'date' => $date],
                    [
                        'student_id' => $enrolment->student_id,
                        'section_id' => $enrolment->section_id,
                        'academic_year_id' => $year->id,
                        'status' => $status,
                        'remarks' => $status === Attendance::STATUS_LEAVE ? 'Approved leave' : null,
                        'marked_by' => $markedBy,
                    ]
                );
            }
        }
    }

    /**
     * @param  list<string>  $weeklyHolidays
     * @return list<string> oldest first
     */
    private function lastSchoolDays(AcademicYear $year, array $weeklyHolidays): array
    {
        $end = min(CarbonImmutable::now('Asia/Dhaka')->toDateString(), $year->end_date->toDateString());
        $start = $year->start_date->toDateString();

        if ($end < $start) {
            return [];
        }

        $holidays = Holiday::whereBetween('date', [$start, $end])->get()->keyBy('date')->all();
        $days = SchoolDays::between($start, $end, $weeklyHolidays, $holidays);

        return array_slice($days, -self::DAYS);
    }

    /**
     * Mostly present, with a few absences, late arrivals and leave.
     */
    private function statusFor(int $enrolmentId, string $date): string
    {
        $bucket = crc32("{$enrolmentId}|{$date}") % 20;

        return match (true) {
            $bucket === 0, $bucket === 1 => Attendance::STATUS_ABSENT,
            $bucket === 2, $bucket === 3 => Attendance::STATUS_LATE,
            $bucket === 4 => Attendance::STATUS_LEAVE,
            default => Attendance::STATUS_PRESENT,
        };
    }
}
