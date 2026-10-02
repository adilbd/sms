<?php

namespace App\Repositories\Eloquent;

use App\Models\Certificate;
use App\Models\Student;
use App\Repositories\Contracts\CertificateRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CertificateRepository extends EloquentRepository implements CertificateRepositoryInterface
{
    protected string $model = Certificate::class;

    public function lockStudent(int $studentId): Student
    {
        return Student::query()->whereKey($studentId)->lockForUpdate()->firstOrFail();
    }

    public function lockCertificate(Certificate $certificate): Certificate
    {
        return Certificate::query()->whereKey($certificate->id)->lockForUpdate()->firstOrFail();
    }

    public function nextSerialNumber(string $type, int $year): int
    {
        // Lock the row first, as FeePaymentRepository::nextReceiptNumber() does: only when
        // it is missing is it inserted (a concurrent insert is ignored) and locked again.
        $counter = fn () => DB::table('certificate_counters')
            ->where('type', $type)->where('year', $year)->lockForUpdate()->value('last_number');

        $last = $counter();

        if ($last === null) {
            DB::table('certificate_counters')->insertOrIgnore(['type' => $type, 'year' => $year, 'last_number' => 0]);
            $last = $counter();
        }

        $next = (int) $last + 1;

        DB::table('certificate_counters')->where('type', $type)->where('year', $year)->update(['last_number' => $next]);

        return $next;
    }

    public function hasActiveTransfer(int $studentId): bool
    {
        return Certificate::query()
            ->where('student_id', $studentId)
            ->where('type', Certificate::TYPE_TRANSFER)
            ->whereNull('cancelled_at')
            ->exists();
    }

    public function loadDetail(Certificate $certificate): Certificate
    {
        return $certificate->load(['student', 'issuer', 'canceller', 'enrolment']);
    }

    protected function query(): Builder
    {
        return parent::query()
            ->with(['student', 'issuer', 'canceller'])
            ->orderByDesc('certificates.issued_on')
            ->orderByDesc('certificates.id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach (['type', 'student_id', 'academic_year_id'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->where("certificates.{$column}", $filters[$column]);
            }
        }

        if (filled($filters['from'] ?? null)) {
            $query->where('certificates.issued_on', '>=', $filters['from']);
        }

        if (filled($filters['to'] ?? null)) {
            $query->where('certificates.issued_on', '<=', $filters['to']);
        }

        if (filled($filters['status'] ?? null)) {
            $filters['status'] === Certificate::STATUS_CANCELLED
                ? $query->whereNotNull('certificates.cancelled_at')
                : $query->whereNull('certificates.cancelled_at');
        }

        if (filled($filters['search'] ?? null)) {
            $search = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], (string) $filters['search']);

            $query->where(function (Builder $q) use ($search) {
                $q->where('certificates.serial_no', 'like', "%{$search}%")
                    ->orWhereHas('student', fn (Builder $s) => $s
                        ->where('name_en', 'like', "%{$search}%")
                        ->orWhere('name_bn', 'like', "%{$search}%")
                        ->orWhere('student_id', 'like', "%{$search}%"));
            });
        }

        // Built by the service from the signed-in teacher, never from input.
        if (is_array($filters['scope_section_ids'] ?? null)) {
            $query->whereHas('enrolment', function (Builder $e) use ($filters) {
                $e->whereIn('section_id', $filters['scope_section_ids']);

                // The scope year (the active year); no year means no enrolment matches.
                filled($filters['scope_academic_year_id'] ?? null)
                    ? $e->where('academic_year_id', $filters['scope_academic_year_id'])
                    : $e->whereRaw('1 = 0');
            });
        }

        return $query;
    }
}
