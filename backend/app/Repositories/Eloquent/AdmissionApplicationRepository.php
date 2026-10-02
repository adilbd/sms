<?php

namespace App\Repositories\Eloquent;

use App\Models\AdmissionApplication;
use App\Repositories\Contracts\AdmissionApplicationRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class AdmissionApplicationRepository extends EloquentRepository implements AdmissionApplicationRepositoryInterface
{
    protected string $model = AdmissionApplication::class;

    public function existsForBirthRegistration(int $roundId, string $birthRegistrationNumber): bool
    {
        return AdmissionApplication::withTrashed()
            ->where('round_id', $roundId)
            ->where('birth_registration_number', $birthRegistrationNumber)
            ->exists();
    }

    public function nextApplicationNumber(int $year): int
    {
        // Lock the row first. INSERT IGNORE on an existing row takes a shared lock on MySQL,
        // and two submissions doing that and then SELECT ... FOR UPDATE would deadlock. Only
        // when the row is missing (first application of the year) is it inserted, and then
        // locked again; a concurrent insert is ignored. Same as
        // FeePaymentRepository::nextReceiptNumber().
        $counter = fn () => DB::table('admission_application_counters')->where('year', $year)->lockForUpdate()->value('last_number');

        $last = $counter();

        if ($last === null) {
            DB::table('admission_application_counters')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
            $last = $counter();
        }

        $next = (int) $last + 1;

        DB::table('admission_application_counters')->where('year', $year)->update(['last_number' => $next]);

        return $next;
    }

    public function findForStatusLookup(string $applicationNo, string $dateOfBirth): ?AdmissionApplication
    {
        return AdmissionApplication::query()
            ->with(['round', 'class', 'shift'])
            ->where('application_no', $applicationNo)
            ->whereDate('date_of_birth', $dateOfBirth)
            ->first();
    }

    public function findWithRoundAndClass(int $id): ?AdmissionApplication
    {
        return AdmissionApplication::query()->with(['round', 'class', 'shift'])->find($id);
    }

    public function lockForUpdate(AdmissionApplication $application): AdmissionApplication
    {
        return AdmissionApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();
    }

    public function countSeatsTaken(int $roundId, int $classId, ?int $exceptId = null): int
    {
        return AdmissionApplication::query()
            ->where('round_id', $roundId)
            ->where('class_id', $classId)
            ->whereIn('status', AdmissionApplication::SEAT_STATUSES)
            ->when($exceptId, fn (Builder $q) => $q->whereKeyNot($exceptId))
            ->count();
    }

    public function countsByStatus(array $filters): array
    {
        $counts = $this->applyFilters(AdmissionApplication::query(), Arr::except($filters, ['status']))
            ->reorder()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(AdmissionApplication::STATUSES)
            ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])
            ->all();
    }

    public function loadDetail(AdmissionApplication $application): AdmissionApplication
    {
        return $application->load(['round', 'class', 'shift', 'student', 'decider']);
    }

    protected function query(): Builder
    {
        return parent::query()
            ->with(['round', 'class'])
            ->orderByDesc('id');
    }

    protected function applyFilters(Builder $query, array $filters): Builder
    {
        foreach (['round_id', 'class_id', 'status'] as $column) {
            if (filled($filters[$column] ?? null)) {
                $query->where($column, $filters[$column]);
            }
        }

        if (filled($filters['search'] ?? null)) {
            $term = '%'.addcslashes((string) $filters['search'], '%_\\').'%';
            $sensitive = (bool) ($filters['search_sensitive'] ?? false);

            $query->where(function (Builder $q) use ($term, $sensitive) {
                $q->where('name_en', 'like', $term)
                    ->orWhere('name_bn', 'like', $term)
                    ->orWhere('application_no', 'like', $term);

                // Mobiles and the birth registration number are only searchable by callers
                // allowed to see them, or the list would confirm guesses.
                if ($sensitive) {
                    $q->orWhere('guardian_mobile', 'like', $term)
                        ->orWhere('father_mobile', 'like', $term)
                        ->orWhere('mother_mobile', 'like', $term)
                        ->orWhere('birth_registration_number', 'like', $term);
                }
            });
        }

        return $query;
    }
}
