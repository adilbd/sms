<?php

namespace App\Services;

use App\Models\Period;
use App\Repositories\Contracts\PeriodRepositoryInterface;
use App\Repositories\Contracts\RoutineRepositoryInterface;
use App\Repositories\Contracts\ShiftRepositoryInterface;
use App\Support\UniqueViolation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The bell schedule of each shift. Periods of one shift never overlap in time (periods of
 * different shifts may), numbers are unique per shift, and a period that routine slots use
 * can't be deleted, turned into a break, or moved so that it creates a teacher or room clash.
 */
class PeriodService
{
    public function __construct(
        private PeriodRepositoryInterface $periods,
        private ShiftRepositoryInterface $shifts,
        private RoutineRepositoryInterface $routines,
    ) {}

    /**
     * @param  array{shift_id?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->periods->paginate($filters, $perPage);
    }

    /**
     * @param  array{shift_id: int, number: int, name_en: string, name_bn: string, start_time: string, end_time: string, is_break?: bool}  $data
     */
    public function create(array $data): Period
    {
        $data = $this->normalizeTimes($data);

        return DB::transaction(function () use ($data) {
            // Serializes creates and updates in one shift, so two requests can't both pass
            // the overlap check against each other's old state.
            $this->shifts->lockForUpdate([(int) $data['shift_id']]);

            $this->ensureTimesAreValid(new Period($data), null);

            return $this->withUniqueNumber(fn () => $this->periods->create($data));
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Period $period, array $data): Period
    {
        $data = $this->normalizeTimes($data);

        return DB::transaction(function () use ($period, $data) {
            // Lock order: shift, then (when the input carries a time) every academic year in
            // ascending id, then the period row. The period comes after the years because a
            // routine save locks section, then year, and its insert of routine_slots rows
            // makes the foreign key check share-lock the parent periods row; taking the
            // period first here would let the two wait on each other (a deadlock).
            $this->shifts->lockForUpdate([(int) $period->shift_id]);

            // A save in ANY year may add or remove slots of this period, so every year is
            // locked; once all are held no save is in flight and the slots read afterwards
            // are final.
            if (array_key_exists('start_time', $data) || array_key_exists('end_time', $data)) {
                $this->routines->lockAcademicYears();
            }

            // Re-read under the lock, so the checks below use the current state, not the
            // route-bound copy.
            $period = $this->periods->lockForUpdate($period);

            // Checked against the period with the input applied, so a partial update that
            // sends only one of the two times is still validated.
            $merged = (clone $period)->fill($data);
            $moves = $merged->start_time !== $period->start_time || $merged->end_time !== $period->end_time;

            $used = $this->periods->isUsedInRoutine($period);

            if ($used && $merged->is_break) {
                throw ValidationException::withMessages([
                    'is_break' => ['This period holds classes in a routine, so it cannot become a break.'],
                ]);
            }

            $this->ensureTimesAreValid($merged, $period->id);

            if ($used && $moves) {
                $this->ensureMovedSlotsDoNotClash($merged);
            }

            return $this->withUniqueNumber(fn () => $this->periods->update($period, $data));
        });
    }

    public function delete(Period $period): void
    {
        abort_if($this->periods->isUsedInRoutine($period), 409, 'Period is used in class routines and cannot be deleted.');

        $this->periods->delete($period);
    }

    private function ensureTimesAreValid(Period $period, ?int $exceptId): void
    {
        if (substr((string) $period->end_time, 0, 5) <= substr((string) $period->start_time, 0, 5)) {
            throw ValidationException::withMessages(['end_time' => ['The end time must be after the start time.']]);
        }

        $other = $this->periods->findOverlapping((int) $period->shift_id, $period->start_time, $period->end_time, $exceptId);

        if ($other) {
            throw ValidationException::withMessages([
                'start_time' => [sprintf(
                    'These times overlap period %d (%s-%s) of the same shift.',
                    $other->number,
                    substr((string) $other->start_time, 0, 5),
                    substr((string) $other->end_time, 0, 5),
                )],
            ]);
        }
    }

    /**
     * Moving a used period changes when its slots happen, so each of its teachers and rooms
     * is checked against the other sections at the new time.
     */
    private function ensureMovedSlotsDoNotClash(Period $period): void
    {
        foreach ($this->routines->usingPeriod($period) as $slot) {
            $clash = $slot->staff_id
                ? $this->routines->findTeacherClash((int) $slot->academic_year_id, (int) $slot->section_id, (int) $slot->staff_id, $slot->day, $period->start_time, $period->end_time, $period->id)
                : null;
            $clash ??= $slot->room_key
                ? $this->routines->findRoomClash((int) $slot->academic_year_id, (int) $slot->section_id, $slot->room_key, $slot->day, $period->start_time, $period->end_time, $period->id)
                : null;

            if ($clash) {
                throw ValidationException::withMessages([
                    'start_time' => [sprintf(
                        'Moving this period would double-book a teacher or room already used by %s %s on %s.',
                        $clash->section?->class?->name,
                        $clash->section?->name,
                        ucfirst($clash->day),
                    )],
                ]);
            }
        }
    }

    /**
     * Times are stored as `H:i:s` so string comparison in queries is consistent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeTimes(array $data): array
    {
        foreach (['start_time', 'end_time'] as $key) {
            if (isset($data[$key]) && substr_count($data[$key], ':') === 1) {
                $data[$key] .= ':00';
            }
        }

        return $data;
    }

    /**
     * The request's unique rule runs before the write, so a concurrent request can still
     * hit the index (see SubjectService::withUniqueCode()).
     */
    private function withUniqueNumber(callable $write): Period
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            if (UniqueViolation::is($e, 'periods', ['shift_id', 'number'])) {
                throw ValidationException::withMessages(['number' => ['This period number is already used in the shift.']]);
            }

            throw $e;
        }
    }
}
