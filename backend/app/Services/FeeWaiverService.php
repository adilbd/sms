<?php

namespace App\Services;

use App\Models\StudentFeeWaiver;
use App\Repositories\Contracts\FeeWaiverRepositoryInterface;
use App\Support\UniqueViolation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Per-student discounts on a fee head for an academic year: a percent of the due or a
 * fixed amount, exactly one of the two, one waiver per student, year and head. A waiver
 * only affects dues generated after it is saved; dues that exist already keep their
 * amounts (re-applying a waiver to unpaid dues is deliberately not offered, because a due
 * may already be part-paid and a change would silently rewrite what was agreed).
 */
class FeeWaiverService
{
    public function __construct(private FeeWaiverRepositoryInterface $waivers) {}

    /**
     * @param  array{student_id?: mixed, academic_year_id?: mixed, fee_head_id?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->waivers->paginate($filters, $perPage);
    }

    public function find(StudentFeeWaiver $waiver): StudentFeeWaiver
    {
        return $this->waivers->loadDetail($waiver);
    }

    /**
     * @param  array<string, mixed>  $data  including `approved_by`, the signed-in user
     */
    public function create(array $data): StudentFeeWaiver
    {
        $waiver = new StudentFeeWaiver($data);
        $this->ensureValid($waiver, null);

        return $this->waivers->loadDetail($this->withUniqueWaiver(fn () => $this->waivers->create($data)));
    }

    /**
     * The exactly-one-of rule runs against the waiver with the input applied, so changing
     * a percent waiver to a fixed one has to send `percent: null` with the new amount.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(StudentFeeWaiver $waiver, array $data): StudentFeeWaiver
    {
        $this->ensureValid((clone $waiver)->fill($data), $waiver->id);

        return $this->waivers->loadDetail($this->withUniqueWaiver(fn () => $this->waivers->update($waiver, $data)));
    }

    public function delete(StudentFeeWaiver $waiver): void
    {
        $this->waivers->delete($waiver);
    }

    private function ensureValid(StudentFeeWaiver $waiver, ?int $exceptId): void
    {
        $hasPercent = $waiver->percent !== null;
        $hasFixed = $waiver->fixed_amount !== null;

        if ($hasPercent === $hasFixed) {
            throw ValidationException::withMessages([
                'percent' => ['Enter either a percent or a fixed amount, not both and not neither.'],
            ]);
        }

        if ($this->waivers->existsFor($waiver->student_id, $waiver->academic_year_id, $waiver->fee_head_id, $exceptId)) {
            throw $this->duplicate();
        }
    }

    /**
     * @see SubjectService::withUniqueCode()
     */
    private function withUniqueWaiver(callable $write): StudentFeeWaiver
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            if (UniqueViolation::is($e, 'student_fee_waivers', ['student_id', 'academic_year_id', 'fee_head_id'], 'student_fee_waivers_student_year_head_unique')) {
                throw $this->duplicate();
            }

            throw $e;
        }
    }

    private function duplicate(): ValidationException
    {
        return ValidationException::withMessages([
            'fee_head_id' => ['This student already has a waiver on this fee head for the year.'],
        ]);
    }
}
