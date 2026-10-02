<?php

namespace App\Repositories\Eloquent;

use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\ExamSubject;
use App\Models\Section;
use App\Models\StudentEnrolment;
use App\Repositories\Contracts\ExamResultRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ExamResultRepository implements ExamResultRepositoryInterface
{
    /** What a single result's breakdown and report card show. */
    private const DETAIL = ['student', 'enrolment', 'class', 'section.shift', 'section.mainClassSections.staff', 'exam.academicYear'];

    public function activeEnrolments(Exam $exam, int $classId): Collection
    {
        return StudentEnrolment::query()
            ->where('student_enrolments.class_id', $classId)
            ->activeIn($exam->academic_year_id)
            ->with(['student', 'class', 'section.shift'])
            ->orderBy('student_enrolments.section_id')
            ->orderByRaw('student_enrolments.roll_number is null')
            ->orderBy('student_enrolments.roll_number')
            ->orderBy('student_enrolments.id')
            ->get();
    }

    public function subjectTakers(Exam $exam, ExamSubject $subject): array
    {
        return StudentEnrolment::query()
            ->where('student_enrolments.class_id', $subject->class_id)
            ->activeIn($exam->academic_year_id)
            ->takingSubject($subject)
            ->pluck('student_enrolments.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function marksFor(Exam $exam): Collection
    {
        return ExamMark::query()
            ->whereIn('exam_subject_id', ExamSubject::query()->where('exam_id', $exam->id)->select('id'))
            ->get();
    }

    public function deleteForExam(Exam $exam): void
    {
        ExamResult::query()->where('exam_id', $exam->id)->delete();
    }

    public function replaceForExam(Exam $exam, array $rows): void
    {
        ExamResult::query()->where('exam_id', $exam->id)->delete();

        $now = now();

        foreach (array_chunk($rows, 200) as $chunk) {
            ExamResult::query()->insert(array_map(fn (array $row) => [
                ...$row,
                'exam_id' => $exam->id,
                'subjects' => json_encode($row['subjects'], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));
        }
    }

    public function paginateForExam(Exam $exam, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = ExamResult::query()
            ->where('exam_results.exam_id', $exam->id)
            ->whereHas('student')
            ->join('classes', 'classes.id', '=', 'exam_results.class_id')
            ->join('student_enrolments', 'student_enrolments.id', '=', 'exam_results.enrolment_id')
            ->select('exam_results.*')
            ->with(['student', 'enrolment', 'class', 'section.shift']);

        if (filled($filters['class_id'] ?? null)) {
            $query->where('exam_results.class_id', $filters['class_id']);
        }

        if (filled($filters['section_id'] ?? null)) {
            $query->where('exam_results.section_id', $filters['section_id'])
                ->orderBy('exam_results.section_position');
        } else {
            $query->orderBy('classes.number')
                ->orderBy('exam_results.class_id')
                ->orderBy('exam_results.class_position');
        }

        return $query
            ->orderByRaw('student_enrolments.roll_number is null')
            ->orderBy('student_enrolments.roll_number')
            ->orderBy('exam_results.id')
            ->paginate($perPage);
    }

    public function findForStudent(Exam $exam, int $studentId): ?ExamResult
    {
        return ExamResult::query()
            ->where('exam_id', $exam->id)
            ->where('student_id', $studentId)
            ->with(self::DETAIL)
            ->first();
    }

    public function publishedForStudent(int $studentId): Collection
    {
        return ExamResult::query()
            ->where('exam_results.student_id', $studentId)
            ->whereHas('exam', fn (Builder $q) => $q->where('status', Exam::STATUS_PUBLISHED))
            ->join('exams', 'exams.id', '=', 'exam_results.exam_id')
            ->select('exam_results.*')
            ->with(self::DETAIL)
            ->orderByDesc('exams.start_date')
            ->orderByDesc('exam_results.id')
            ->get();
    }

    public function scheduleFor(int $classId, int $academicYearId): Collection
    {
        return ExamSubject::query()
            ->where('exam_subjects.class_id', $classId)
            ->whereHas('exam', fn (Builder $q) => $q
                ->where('academic_year_id', $academicYearId)
                ->where('status', '!=', Exam::STATUS_DRAFT))
            ->join('exams', 'exams.id', '=', 'exam_subjects.exam_id')
            ->select('exam_subjects.*')
            ->with(['exam', 'subject'])
            ->orderByDesc('exams.start_date')
            ->orderBy('exams.id')
            ->orderBy('exam_subjects.sort_order')
            ->orderBy('exam_subjects.id')
            ->get();
    }

    public function latestPublishedAnnualExam(int $academicYearId, int $classId): ?Exam
    {
        return Exam::query()
            ->where('academic_year_id', $academicYearId)
            ->where('type', Exam::TYPE_ANNUAL)
            ->where('status', Exam::STATUS_PUBLISHED)
            ->whereHas('results', fn (Builder $q) => $q->where('class_id', $classId))
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();
    }

    public function resultsForEnrolments(Exam $exam, array $enrolmentIds): Collection
    {
        return ExamResult::query()
            ->where('exam_id', $exam->id)
            ->whereIn('enrolment_id', $enrolmentIds)
            ->get()
            ->keyBy('enrolment_id');
    }

    public function publishedExams(): Collection
    {
        return Exam::query()
            ->where('exams.status', Exam::STATUS_PUBLISHED)
            ->join('academic_years', 'academic_years.id', '=', 'exams.academic_year_id')
            ->select('exams.*')
            ->with(['academicYear', 'classes.sections' => fn ($q) => $q
                ->where('sections.is_active', true)
                ->with('shift')
                ->orderBy('sections.shift_id')
                ->orderBy('sections.code')
                ->orderBy('sections.id')])
            ->orderByDesc('academic_years.year')
            ->orderByDesc('exams.start_date')
            ->orderByDesc('exams.id')
            ->get();
    }

    public function findPublishedExam(int $id): ?Exam
    {
        return Exam::query()
            ->where('status', Exam::STATUS_PUBLISHED)
            ->find($id);
    }

    public function findSectionWithClass(int $sectionId): ?Section
    {
        return Section::query()->with('class')->find($sectionId);
    }

    public function findForPublicLookup(Exam $exam, array $criteria, string $dateOfBirth): ?ExamResult
    {
        $query = ExamResult::query()
            ->where('exam_id', $exam->id)
            ->whereHas('student', function (Builder $q) use ($criteria, $dateOfBirth) {
                $q->whereDate('date_of_birth', $dateOfBirth);

                if (isset($criteria['student_code'])) {
                    $q->where('student_id', $criteria['student_code']);
                }
            });

        if (! isset($criteria['student_code'])) {
            $query->whereHas('enrolment', function (Builder $q) use ($criteria) {
                $q->where('section_id', $criteria['section_id'])
                    ->where('roll_number', $criteria['roll_number']);

                $criteria['group'] === null ? $q->whereNull('group') : $q->where('group', $criteria['group']);
            });
        }

        return $query->with(self::DETAIL)->first();
    }
}
