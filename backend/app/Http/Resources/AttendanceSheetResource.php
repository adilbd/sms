<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One section's attendance for one date: the section, whether the date is a holiday, and
 * one row per active student with their saved status (null until marked). Wraps the array
 * AttendanceService returns. The student is a narrow shape (no contact or guardian
 * details; the teacher role has `view-attendance` but not `view-students`).
 *
 * @property array{section: \App\Models\Section, academic_year: \App\Models\AcademicYear, date: string, holiday: ?array, enrolments: \Illuminate\Support\Collection} $resource
 */
class AttendanceSheetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $section = $this->resource['section'];
        $holiday = $this->resource['holiday'];

        return [
            'date' => $this->resource['date'],
            'academic_year_id' => $this->resource['academic_year']->id,
            'is_holiday' => $holiday !== null,
            'holiday' => $holiday,
            'section' => [
                'id' => $section->id,
                'class_id' => $section->class_id,
                'name' => $section->name,
                'code' => $section->code,
                'group' => $section->group,
            ],
            'students' => $this->resource['enrolments']->map(function ($enrolment) {
                $row = $enrolment->attendances->first();

                return [
                    'student_id' => $enrolment->student_id,
                    'enrolment_id' => $enrolment->id,
                    'student_code' => $enrolment->student->student_id,
                    'name_en' => $enrolment->student->name_en,
                    'name_bn' => $enrolment->student->name_bn,
                    'roll_number' => $enrolment->roll_number,
                    'status' => $row?->status,
                    'remarks' => $row?->remarks,
                    'marked_by' => $row?->marked_by,
                ];
            })->values(),
        ];
    }
}
