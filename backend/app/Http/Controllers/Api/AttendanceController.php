<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\IndexAttendanceReportRequest;
use App\Http\Requests\Attendance\IndexAttendanceSheetRequest;
use App\Http\Requests\Attendance\MonthAttendanceRequest;
use App\Http\Requests\Attendance\SaveAttendanceSheetRequest;
use App\Http\Resources\AttendanceReportResource;
use App\Http\Resources\AttendanceSheetResource;
use App\Http\Resources\StudentAttendanceResource;
use App\Models\Student;
use App\Services\AttendanceService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Daily attendance by section. Reading needs `view-attendance` and saving
 * `mark-attendance`; the class-teacher-or-admin rule is in AttendanceService.
 */
class AttendanceController extends Controller implements HasMiddleware
{
    public function __construct(private AttendanceService $attendance) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:view-attendance', only: ['sheet', 'report', 'student']),
            new Middleware('permission:mark-attendance', only: ['saveSheet']),
        ];
    }

    public function sheet(IndexAttendanceSheetRequest $request)
    {
        $data = $request->validated();

        return new AttendanceSheetResource(
            $this->attendance->sheet($request->user(), (int) $data['section_id'], $data['date'] ?? null)
        );
    }

    public function saveSheet(SaveAttendanceSheetRequest $request)
    {
        return (new AttendanceSheetResource($this->attendance->save($request->user(), $request->validated())))
            ->additional(['message' => 'Attendance saved successfully']);
    }

    public function report(IndexAttendanceReportRequest $request)
    {
        $data = $request->validated();

        return new AttendanceReportResource(
            $this->attendance->report($request->user(), (int) $data['section_id'], $data['month'] ?? null)
        );
    }

    public function student(MonthAttendanceRequest $request, Student $student)
    {
        return new StudentAttendanceResource(
            $this->attendance->studentMonth($request->user(), $student, $request->validated('month'))
        );
    }
}
