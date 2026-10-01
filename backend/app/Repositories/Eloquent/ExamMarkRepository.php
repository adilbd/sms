<?php

namespace App\Repositories\Eloquent;

use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamSubject;
use App\Models\Section;
use App\Models\StudentEnrolment;
use App\Repositories\Contracts\ExamMarkRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ExamMarkRepository implements ExamMarkRepositoryInterface
{
    public function lockSection(int $sectionId): Section
    {
        return Section::query()->whereKey($sectionId)->lockForUpdate()->firstOrFail();
    }

    public function sheetEnrolments(Exam $exam, Section $section, ExamSubject $subject): Collection
    {
        return StudentEnrolment::query()
            ->where('section_id', $section->id)
            ->where('academic_year_id', $exam->academic_year_id)
            ->where('status', StudentEnrolment::STATUS_ACTIVE)
            // A soft-deleted student is off the sheet.
            ->whereHas('student')
            ->where(function (Builder $q) use ($subject) {
                if ($subject->type === ClassSubject::TYPE_OPTIONAL) {
                    $q->where('optional_subject_id', $subject->subject_id);
                }

                if ($subject->group !== null) {
                    $q->where('group', $subject->group);
                }
            })
            ->with([
                'student',
                'examMarks' => fn ($q) => $q->where('exam_subject_id', $subject->id),
            ])
            ->orderByRaw('roll_number is null')
            ->orderBy('roll_number')
            ->orderBy('id')
            ->get();
    }

    public function saveRows(ExamSubject $subject, array $rows, ?int $userId): void
    {
        foreach ($rows as $row) {
            $blank = ! $row['is_absent'] && $row['written'] === null && $row['mcq'] === null && $row['practical'] === null;

            $existing = ExamMark::query()
                ->where('exam_subject_id', $subject->id)
                ->where('student_id', $row['student_id'])
                ->first();

            if ($blank) {
                $existing?->delete();

                continue;
            }

            $attributes = [
                'enrolment_id' => $row['enrolment_id'],
                'written' => $row['written'],
                'mcq' => $row['mcq'],
                'practical' => $row['practical'],
                'is_absent' => $row['is_absent'],
                'entered_by' => $userId,
            ];

            if ($existing) {
                $existing->update($attributes);
            } else {
                ExamMark::query()->create([
                    'exam_subject_id' => $subject->id,
                    'student_id' => $row['student_id'],
                    ...$attributes,
                ]);
            }
        }
    }
}
