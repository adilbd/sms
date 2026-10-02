<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Academic years run January to December (see CLAUDE.md). Exactly one year is active
 * at a time, enforced by activate().
 */
class AcademicYearService
{
    public function __construct(private AcademicYearRepositoryInterface $years) {}

    /**
     * @param  array{is_active?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->years->paginate($filters, $perPage);
    }

    public function create(array $data): AcademicYear
    {
        $data = $this->applyDefaults($data);

        // A new model carries the column defaults, so the check runs against the
        // defaulted dates too (see SubjectService::create() for the same pattern).
        $this->ensureDatesWithinYear(new AcademicYear($data));

        return $this->withUniqueFields(fn () => $this->years->create($data));
    }

    public function update(AcademicYear $academicYear, array $data): AcademicYear
    {
        // Checked against the model with the input applied, so a partial update that
        // only sends one of year/start_date/end_date is still validated against the
        // saved values for the others (see SubjectService::update() for the same
        // pattern).
        if (array_key_exists('year', $data) || array_key_exists('start_date', $data) || array_key_exists('end_date', $data)) {
            $this->ensureDatesWithinYear((clone $academicYear)->fill($data));
        }

        return $this->withUniqueFields(fn () => $this->years->update($academicYear, $data));
    }

    public function delete(AcademicYear $academicYear): void
    {
        abort_if($academicYear->is_active, 409, 'Academic year is active and cannot be deleted.');

        // Foreign keys don't protect soft-deleted rows, so check every reference here
        // (see SubjectService::delete() for the same pattern).
        abort_if($this->years->hasStudents($academicYear), 409, 'Academic year has students and cannot be deleted.');
        abort_if($this->years->hasExams($academicYear), 409, 'Academic year has exams and cannot be deleted.');
        abort_if($this->years->hasFeeRatesOrDues($academicYear), 409, 'Academic year has fee rates or fee dues and cannot be deleted.');
        abort_if($this->years->hasClassTeacherRows($academicYear), 409, 'Academic year has class teacher assignments and cannot be deleted.');
        abort_if($this->years->hasSubjectAssignments($academicYear), 409, 'Academic year has subject assignments and cannot be deleted.');

        abort_if($this->years->hasHolidays($academicYear), 409, 'Academic year has holidays and cannot be deleted.');

        abort_if($this->years->hasRoutineSlots($academicYear), 409, 'Academic year has class routines and cannot be deleted.');
        abort_if($this->years->hasHomework($academicYear), 409, 'Academic year has homework and cannot be deleted.');

        abort_if($this->years->hasCertificates($academicYear), 409, 'Academic year has certificates and cannot be deleted.');

        $this->years->delete($academicYear);
    }

    /**
     * Deactivates every other year inside the same transaction, so exactly one year
     * stays active.
     */
    public function activate(AcademicYear $academicYear): AcademicYear
    {
        return DB::transaction(function () use ($academicYear) {
            $this->years->deactivateAllExcept($academicYear);

            return $this->years->update($academicYear, ['is_active' => true]);
        });
    }

    /**
     * Defaults start_date/end_date to Jan 1 / Dec 31 of `year`, and code to the year,
     * for any of those fields the caller didn't send.
     */
    private function applyDefaults(array $data): array
    {
        if (empty($data['year'])) {
            return $data;
        }

        $year = (int) $data['year'];

        $data['start_date'] ??= Carbon::create($year, 1, 1)->toDateString();
        $data['end_date'] ??= Carbon::create($year, 12, 31)->toDateString();
        $data['code'] ??= (string) $year;

        return $data;
    }

    private function ensureDatesWithinYear(AcademicYear $academicYear): void
    {
        if (! $academicYear->year) {
            return;
        }

        $start = Carbon::create((int) $academicYear->year, 1, 1)->startOfDay();
        $end = Carbon::create((int) $academicYear->year, 12, 31)->endOfDay();

        if ($academicYear->start_date && ($academicYear->start_date->lt($start) || $academicYear->start_date->gt($end))) {
            throw ValidationException::withMessages([
                'start_date' => ["The start date must fall within {$academicYear->year}."],
            ]);
        }

        if ($academicYear->end_date && ($academicYear->end_date->lt($start) || $academicYear->end_date->gt($end))) {
            throw ValidationException::withMessages([
                'end_date' => ["The end date must fall within {$academicYear->year}."],
            ]);
        }

        if ($academicYear->start_date && $academicYear->end_date && $academicYear->end_date->lte($academicYear->start_date)) {
            throw ValidationException::withMessages([
                'end_date' => ['The end date must be after the start date.'],
            ]);
        }
    }

    /**
     * The unique rules run before the write, so a concurrent request can still hit the
     * database index (see SubjectService::withUniqueCode()). AcademicYear has two
     * unique columns (year, code); best-effort report whichever the database's own
     * message names, defaulting to "code" when that can't be told.
     */
    private function withUniqueFields(callable $write): AcademicYear
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            $field = str_contains($e->getMessage(), 'year') ? 'year' : 'code';

            throw ValidationException::withMessages([
                $field => ["The {$field} has already been taken."],
            ]);
        }
    }
}
