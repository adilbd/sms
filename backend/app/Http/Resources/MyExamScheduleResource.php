<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One student's exam schedule for the student and guardian roles: each exam open to them
 * with only the subject, date and time (no marks scheme). Wraps an entry of
 * ResultService::ownSchedule().
 *
 * @property array{student: \App\Models\Student, exams: list<array{exam: \App\Models\Exam, subjects: list<\App\Models\ExamSubject>}>} $resource
 */
class MyExamScheduleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $student = $this->resource['student'];

        return [
            'student_id' => $student->id,
            'student_code' => $student->student_id,
            'name_en' => $student->name_en,
            'name_bn' => $student->name_bn,
            'class_id' => $student->currentEnrolment?->class_id,
            'exams' => array_map(fn (array $entry) => [
                'id' => $entry['exam']->id,
                'name_en' => $entry['exam']->name_en,
                'name_bn' => $entry['exam']->name_bn,
                'type' => $entry['exam']->type,
                'start_date' => $entry['exam']->start_date?->toDateString(),
                'end_date' => $entry['exam']->end_date?->toDateString(),
                'status' => $entry['exam']->status,
                'subjects' => array_map(fn ($subject) => [
                    'subject_id' => $subject->subject_id,
                    'name_en' => $subject->subject?->name,
                    'name_bn' => $subject->subject?->name_bn,
                    'exam_date' => $subject->exam_date?->toDateString(),
                    // The `time` column comes back as "H:i:s"; trimmed to "H:i".
                    'start_time' => $subject->start_time ? substr((string) $subject->start_time, 0, 5) : null,
                    'end_time' => $subject->end_time ? substr((string) $subject->end_time, 0, 5) : null,
                ], $entry['subjects']),
            ], $this->resource['exams']),
        ];
    }
}
