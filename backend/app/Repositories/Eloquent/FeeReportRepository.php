<?php

namespace App\Repositories\Eloquent;

use App\Models\FeeDue;
use App\Models\FeePayment;
use App\Models\FeePaymentAllocation;
use App\Repositories\Contracts\FeeReportRepositoryInterface;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class FeeReportRepository implements FeeReportRepositoryInterface
{
    public function duesInSection(int $sectionId, int $academicYearId, ?string $month): Collection
    {
        return FeeDue::query()
            ->whereHas('enrolment', fn (Builder $q) => $q->where('section_id', $sectionId)->where('academic_year_id', $academicYearId))
            ->when($month !== null, function (Builder $q) use ($month) {
                $first = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
                $q->whereBetween('due_date', [$first->toDateString(), $first->endOfMonth()->toDateString()]);
            })
            ->with(['head', 'student', 'enrolment'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    public function paymentsBetween(CarbonInterface $from, CarbonInterface $to, array $filters = []): Collection
    {
        return FeePayment::query()
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $to)
            ->when(filled($filters['method'] ?? null), fn (Builder $q) => $q->where('method', $filters['method']))
            ->when(filled($filters['collected_by'] ?? null), fn (Builder $q) => $q->where('collected_by', $filters['collected_by']))
            ->with(['student', 'collector'])
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();
    }

    public function duesOfStudentInYear(int $studentId, int $academicYearId): Collection
    {
        return FeeDue::query()
            ->where('student_id', $studentId)
            ->whereHas('enrolment', fn (Builder $q) => $q->where('academic_year_id', $academicYearId))
            ->with('head')
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();
    }

    public function allocationsOfStudentInYear(int $studentId, int $academicYearId): Collection
    {
        return FeePaymentAllocation::query()
            ->whereHas('due', fn (Builder $q) => $q
                ->where('student_id', $studentId)
                ->whereHas('enrolment', fn (Builder $e) => $e->where('academic_year_id', $academicYearId)))
            ->with('payment')
            ->orderBy('id')
            ->get();
    }

    public function paymentsOfStudent(int $studentId): Collection
    {
        return FeePayment::query()
            ->where('student_id', $studentId)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();
    }
}
