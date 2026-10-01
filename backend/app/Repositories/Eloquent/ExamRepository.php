<?php

namespace App\Repositories\Eloquent;

use App\Models\Classes;
use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamSubject;
use App\Repositories\Contracts\ExamRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class ExamRepository extends EloquentRepository implements ExamRepositoryInterface
{
    protected string $model = Exam::class;

    public function loadDetail(Exam $exam): Exam
    {
        return $exam->load(['academicYear', 'classes'])->loadCount('examSubjects');
    }

    public function lockExam(Exam $exam): Exam
    {
        return Exam::query()->whereKey($exam->getKey())->lockForUpdate()->firstOrFail();
    }

    public function lockSubject(ExamSubject $subject): ExamSubject
    {
        return ExamSubject::query()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
    }

    public function classesByIds(array $ids): Collection
    {
        return Classes::query()->whereIn('id', $ids)->orderBy('id')->get();
    }

    public function classIds(Exam $exam): array
    {
        return $exam->examSubjects()->distinct()->orderBy('class_id')->pluck('class_id')
            ->map(fn ($id) => (int) $id)->all();
    }

    public function subjectsFor(Exam $exam, ?int $classId = null, ?array $onlyAssigned = null): Collection
    {
        return $exam->examSubjects()
            ->with(['subject', 'class'])
            ->when($classId !== null, fn (Builder $q) => $q->where('exam_subjects.class_id', $classId))
            ->when($onlyAssigned !== null, fn (Builder $q) => $q->where(function (Builder $q) use ($onlyAssigned) {
                // Grouped so the ORs can't escape the other filters; no pairs matches nothing.
                $q->whereRaw('1 = 0');

                foreach ($onlyAssigned as $pair) {
                    $q->orWhere(fn (Builder $q) => $q
                        ->where('exam_subjects.class_id', $pair['class_id'])
                        ->where('exam_subjects.subject_id', $pair['subject_id']));
                }
            }))
            ->join('classes', 'classes.id', '=', 'exam_subjects.class_id')
            ->select('exam_subjects.*')
            ->orderBy('classes.number')
            ->orderBy('exam_subjects.class_id')
            ->orderBy('exam_subjects.sort_order')
            ->orderBy('exam_subjects.id')
            ->get();
    }

    public function findSubject(Exam $exam, int $examSubjectId): ?ExamSubject
    {
        return $exam->examSubjects()->with(['subject', 'class'])->whereKey($examSubjectId)->first();
    }

    public function replaceClassSubjects(Exam $exam, Classes $class, array $rows): void
    {
        $existing = ExamSubject::query()
            ->where('exam_id', $exam->id)
            ->where('class_id', $class->id)
            ->get()
            ->keyBy(fn (ExamSubject $row) => $row->subject_id.'|'.($row->group ?? ''));

        $keep = [];

        foreach ($rows as $row) {
            $current = $existing->get($row['subject_id'].'|'.($row['group'] ?? ''));

            if ($current) {
                $current->update($row);
                $keep[] = $current->id;
            } else {
                $keep[] = ExamSubject::query()->create([...$row, 'exam_id' => $exam->id, 'class_id' => $class->id])->id;
            }
        }

        ExamSubject::query()
            ->where('exam_id', $exam->id)
            ->where('class_id', $class->id)
            ->whereNotIn('id', $keep)
            ->delete();
    }

    public function deleteClassSubjects(Exam $exam, int $classId): void
    {
        ExamSubject::query()->where('exam_id', $exam->id)->where('class_id', $classId)->delete();
    }

    public function deleteSubjects(Exam $exam): void
    {
        ExamSubject::query()->where('exam_id', $exam->id)->delete();
    }

    public function updateSubject(ExamSubject $subject, array $attributes): ExamSubject
    {
        $subject->update($attributes);

        return $subject->load(['subject', 'class']);
    }

    public function hasMarks(Exam $exam): bool
    {
        return ExamMark::query()
            ->whereIn('exam_subject_id', ExamSubject::query()->where('exam_id', $exam->id)->select('id'))
            ->exists();
    }

    public function hasResults(Exam $exam): bool
    {
        return $exam->results()->exists();
    }

    public function hasMarksForClass(Exam $exam, int $classId): bool
    {
        return ExamMark::query()
            ->whereIn('exam_subject_id', ExamSubject::query()
                ->where('exam_id', $exam->id)->where('class_id', $classId)->select('id'))
            ->exists();
    }

    public function hasMarksForSubject(ExamSubject $subject): bool
    {
        return ExamMark::query()->where('exam_subject_id', $subject->id)->exists();
    }

    protected function query(): Builder
    {
        return parent::query()
            ->with(['academicYear', 'classes'])
            ->withCount('examSubjects')
            ->orderByDesc('start_date')
            ->orderByDesc('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach (['academic_year_id', 'type', 'status'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->where($column, $filters[$column]);
            }
        }

        if (filled($filters['search'] ?? null)) {
            $search = $filters['search'];

            // Grouped so the ORs can't escape the other filters.
            $query->where(fn (Builder $q) => $q
                ->where('name_en', 'like', "%{$search}%")
                ->orWhere('name_bn', 'like', "%{$search}%")
                ->orWhere('code', 'like', "%{$search}%"));
        }

        return $query;
    }
}
