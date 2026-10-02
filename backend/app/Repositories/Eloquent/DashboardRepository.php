<?php

namespace App\Repositories\Eloquent;

use App\Models\Attendance;
use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\ExamSubject;
use App\Models\FeeDue;
use App\Models\FeePayment;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Repositories\Contracts\DashboardRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class DashboardRepository implements DashboardRepositoryInterface
{
    public function enrolmentGroups(int $academicYearId, ?array $sectionIds): SupportCollection
    {
        return StudentEnrolment::query()
            ->activeIn($academicYearId)
            ->when($sectionIds !== null, fn ($q) => $q->whereIn('student_enrolments.section_id', $sectionIds))
            ->select('student_enrolments.section_id', 'student_enrolments.group', 'student_enrolments.optional_subject_id')
            ->selectRaw('count(*) as students')
            ->groupBy('student_enrolments.section_id', 'student_enrolments.group', 'student_enrolments.optional_subject_id')
            ->toBase()
            ->get();
    }

    public function sections(?array $sectionIds, int $academicYearId): Collection
    {
        return Section::query()
            ->when($sectionIds !== null, fn ($q) => $q->whereIn('id', $sectionIds))
            ->with([
                'class',
                'shift',
                'mainClassSections' => fn ($q) => $q->where('academic_year_id', $academicYearId)->with('staff'),
            ])
            ->orderBy('class_id')
            ->orderBy('shift_id')
            ->orderBy('code')
            ->orderBy('id')
            ->get();
    }

    public function staffCounts(): array
    {
        $row = Staff::query()
            ->where('status', Staff::STATUS_ACTIVE)
            ->selectRaw('count(*) as total')
            ->selectRaw('coalesce(sum(case when category = ? then 1 else 0 end), 0) as teachers', [Staff::CATEGORY_TEACHER])
            ->selectRaw('coalesce(sum(case when user_id is not null then 1 else 0 end), 0) as with_login')
            ->toBase()
            ->first();

        return [
            'total' => (int) $row->total,
            'teachers' => (int) $row->teachers,
            'with_login' => (int) $row->with_login,
        ];
    }

    public function attendanceCounts(int $academicYearId, string $date, ?array $sectionIds): SupportCollection
    {
        return Attendance::query()
            ->where('academic_year_id', $academicYearId)
            ->where('date', $date)
            ->when($sectionIds !== null, fn ($q) => $q->whereIn('section_id', $sectionIds))
            ->selectRaw('section_id, status, count(*) as total')
            ->groupBy('section_id', 'status')
            ->toBase()
            ->get();
    }

    public function latestExam(int $academicYearId): ?Exam
    {
        return Exam::query()
            ->where('academic_year_id', $academicYearId)
            ->where('status', '!=', Exam::STATUS_DRAFT)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();
    }

    public function resultsByClass(int $examId): SupportCollection
    {
        return ExamResult::query()
            ->join('classes', 'classes.id', '=', 'exam_results.class_id')
            ->where('exam_results.exam_id', $examId)
            ->selectRaw('exam_results.class_id, classes.number as class_number, classes.name as class_name, count(*) as students, sum(case when exam_results.is_pass then 1 else 0 end) as passed, max(exam_results.gpa) as top_gpa')
            ->groupBy('exam_results.class_id', 'classes.number', 'classes.name')
            ->orderBy('classes.number')
            ->toBase()
            ->get();
    }

    public function resultsBySection(int $examId, array $sectionIds): SupportCollection
    {
        return ExamResult::query()
            ->where('exam_id', $examId)
            ->whereIn('section_id', $sectionIds)
            ->selectRaw('section_id, count(*) as students, sum(case when is_pass then 1 else 0 end) as passed')
            ->groupBy('section_id')
            ->toBase()
            ->get();
    }

    public function openExamSubjects(int $academicYearId): Collection
    {
        return ExamSubject::query()
            ->whereHas('exam', fn ($q) => $q
                ->where('academic_year_id', $academicYearId)
                ->where('status', Exam::STATUS_MARKS_ENTRY))
            ->with(['exam', 'subject'])
            ->orderBy('exam_id')
            ->orderBy('class_id')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function enteredMarkCounts(array $examSubjectIds, ?array $sectionIds): SupportCollection
    {
        if ($examSubjectIds === []) {
            return collect();
        }

        return ExamMark::query()
            ->join('student_enrolments', 'student_enrolments.id', '=', 'exam_marks.enrolment_id')
            ->whereIn('exam_marks.exam_subject_id', $examSubjectIds)
            ->when($sectionIds !== null, fn ($q) => $q->whereIn('student_enrolments.section_id', $sectionIds))
            ->selectRaw('exam_marks.exam_subject_id, student_enrolments.section_id, count(*) as entered')
            ->groupBy('exam_marks.exam_subject_id', 'student_enrolments.section_id')
            ->toBase()
            ->get();
    }

    public function recentExams(int $academicYearId, int $limit): Collection
    {
        return Exam::query()
            ->where('academic_year_id', $academicYearId)
            ->whereIn('status', [Exam::STATUS_PROCESSED, Exam::STATUS_PUBLISHED])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function dueTotals(int $academicYearId, string $from, string $to): array
    {
        $row = FeeDue::query()
            ->join('student_enrolments', 'student_enrolments.id', '=', 'fee_dues.enrolment_id')
            ->where('student_enrolments.academic_year_id', $academicYearId)
            ->whereBetween('fee_dues.due_date', [$from, $to])
            ->selectRaw('sum(fee_dues.net_amount) as net, sum(fee_dues.paid_amount) as paid')
            ->toBase()
            ->first();

        return ['net' => $row->net, 'paid' => $row->paid];
    }

    public function overdueTotals(int $academicYearId, string $today): array
    {
        $row = FeeDue::query()
            ->join('student_enrolments', 'student_enrolments.id', '=', 'fee_dues.enrolment_id')
            ->where('student_enrolments.academic_year_id', $academicYearId)
            ->where('fee_dues.due_date', '<', $today)
            ->whereColumn('fee_dues.paid_amount', '<', 'fee_dues.net_amount')
            ->selectRaw('count(*) as due_count, sum(fee_dues.net_amount - fee_dues.paid_amount) as outstanding')
            ->toBase()
            ->first();

        return ['count' => (int) $row->due_count, 'outstanding' => $row->outstanding];
    }

    public function studentsWithOutstandingDues(int $academicYearId): int
    {
        return FeeDue::query()
            ->join('student_enrolments', 'student_enrolments.id', '=', 'fee_dues.enrolment_id')
            ->where('student_enrolments.academic_year_id', $academicYearId)
            ->whereColumn('fee_dues.paid_amount', '<', 'fee_dues.net_amount')
            ->distinct()
            ->count('fee_dues.student_id');
    }

    public function collectionByMethod(CarbonInterface $from, CarbonInterface $to): SupportCollection
    {
        return FeePayment::query()
            ->whereNull('cancelled_at')
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $to)
            ->selectRaw('method, count(*) as payments, sum(amount) as total')
            ->groupBy('method')
            ->toBase()
            ->get();
    }

    public function recentPayments(int $limit): Collection
    {
        return FeePayment::query()
            ->whereNull('cancelled_at')
            ->with('student')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function recentStudents(int $academicYearId, int $limit): Collection
    {
        return Student::query()
            ->with(['currentEnrolment' => fn ($q) => $q->where('academic_year_id', $academicYearId), 'currentEnrolment.class', 'currentEnrolment.section'])
            ->orderByDesc('admission_date')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
