<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Holiday;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\HolidayRepositoryInterface;
use App\Support\UniqueViolation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * The school's listed holidays. A holiday's academic year is always the one its date
 * falls in, so it is derived here and never sent by the client. Weekly holidays (Friday)
 * are an institute setting, not rows.
 */
class HolidayService
{
    public function __construct(
        private HolidayRepositoryInterface $holidays,
        private AcademicYearRepositoryInterface $years,
    ) {}

    /**
     * @param  array{academic_year_id?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->holidays->paginate($filters, $perPage);
    }

    /**
     * @param  array{date: string, name_en?: ?string, name_bn?: ?string}  $data
     */
    public function create(array $data): Holiday
    {
        $holiday = new Holiday($data);
        $this->ensureHasName($holiday);
        $data['academic_year_id'] = $this->yearFor($holiday->date)->id;

        return $this->withUniqueDate(fn () => $this->holidays->create($data));
    }

    /**
     * The name rule runs against the holiday with the input applied, so a partial update
     * that clears one name is still checked against the other.
     *
     * @param  array{date?: string, name_en?: ?string, name_bn?: ?string}  $data
     */
    public function update(Holiday $holiday, array $data): Holiday
    {
        $merged = (clone $holiday)->fill($data);
        $this->ensureHasName($merged);

        if (array_key_exists('date', $data)) {
            $data['academic_year_id'] = $this->yearFor($merged->date)->id;
        }

        return $this->withUniqueDate(fn () => $this->holidays->update($holiday, $data));
    }

    public function delete(Holiday $holiday): void
    {
        $this->holidays->delete($holiday);
    }

    private function ensureHasName(Holiday $holiday): void
    {
        if (blank($holiday->name_en) && blank($holiday->name_bn)) {
            throw ValidationException::withMessages([
                'name_en' => ['Enter the holiday name in English or Bangla.'],
            ]);
        }
    }

    /**
     * The academic year the date is in: the one for its calendar year, with the date
     * inside the year's start and end dates.
     */
    private function yearFor(string $date): AcademicYear
    {
        $year = $this->years->findByYear((int) substr($date, 0, 4));

        if ($year === null || $date < $year->start_date->toDateString() || $date > $year->end_date->toDateString()) {
            throw ValidationException::withMessages(['date' => ['The date is outside any academic year.']]);
        }

        return $year;
    }

    /**
     * The uniqueness rule runs before the write, so a concurrent request can still hit
     * the database index (see SubjectService::withUniqueCode()).
     */
    private function withUniqueDate(callable $write): Holiday
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            if (UniqueViolation::is($e, 'holidays', ['date'])) {
                throw ValidationException::withMessages(['date' => ['A holiday is already listed on this date.']]);
            }

            throw $e;
        }
    }
}
