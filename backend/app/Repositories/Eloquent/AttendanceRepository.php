<?php

namespace App\Repositories\Eloquent;

use App\Models\Attendance;
use App\Models\StudentEnrolment;
use App\Repositories\Contracts\AttendanceRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AttendanceRepository implements AttendanceRepositoryInterface
{
    public function sheetEnrolments(int $sectionId, int $academicYearId, string $date): Collection
    {
        return StudentEnrolment::query()
            ->where('student_enrolments.section_id', $sectionId)
            ->activeIn($academicYearId)
            ->with([
                'student',
                'attendances' => fn ($q) => $q->where('date', $date),
            ])
            ->orderByRaw('roll_number is null')
            ->orderBy('roll_number')
            ->orderBy('id')
            ->get();
    }

    public function saveRows(array $rows, int $sectionId, int $academicYearId, string $date, ?int $userId): void
    {
        foreach ($rows as $row) {
            Attendance::query()->updateOrCreate(
                ['enrolment_id' => $row['enrolment_id'], 'date' => $date],
                [
                    'student_id' => $row['student_id'],
                    'section_id' => $sectionId,
                    'academic_year_id' => $academicYearId,
                    'status' => $row['status'],
                    'remarks' => $row['remarks'],
                    'marked_by' => $userId,
                ]
            );
        }
    }

    public function reportEnrolments(int $sectionId, int $academicYearId, string $from, string $to): Collection
    {
        $inRange = fn ($q) => $q->where('section_id', $sectionId)->whereBetween('date', [$from, $to]);

        return StudentEnrolment::query()
            ->where('student_enrolments.section_id', $sectionId)
            ->where('student_enrolments.academic_year_id', $academicYearId)
            ->whereHas('student')
            ->where(fn (Builder $q) => $q
                ->where('student_enrolments.status', StudentEnrolment::STATUS_ACTIVE)
                ->orWhereHas('attendances', $inRange))
            ->with(['student', 'attendances' => $inRange])
            ->orderByRaw('roll_number is null')
            ->orderBy('roll_number')
            ->orderBy('id')
            ->get();
    }

    public function recordsForStudent(int $studentId, string $from, string $to): Collection
    {
        return Attendance::query()
            ->where('student_id', $studentId)
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->get(['date', 'status']);
    }
}
