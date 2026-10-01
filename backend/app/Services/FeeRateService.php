<?php

namespace App\Services;

use App\Models\FeeRate;
use App\Repositories\Contracts\ClassRepositoryInterface;
use App\Repositories\Contracts\FeeRateRepositoryInterface;
use App\Support\UniqueViolation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * What a fee head costs per class and academic year. A group (Science, Business Studies,
 * Humanities) is only allowed from Class 9; a rate with no group applies to the whole
 * class and a group's own rate wins over it. There is one rate per head, class, year and
 * group (a null group counts as a value, checked here because a unique index treats nulls
 * as distinct). A rate used by dues can be edited (existing dues keep their amount) but
 * not deleted, and its head, class and year can't change.
 */
class FeeRateService
{
    public function __construct(
        private FeeRateRepositoryInterface $rates,
        private ClassRepositoryInterface $classes,
    ) {}

    /**
     * @param  array{academic_year_id?: mixed, class_id?: mixed, fee_head_id?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->rates->paginate($filters, $perPage);
    }

    public function find(FeeRate $rate): FeeRate
    {
        return $this->rates->loadDetail($rate);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): FeeRate
    {
        $rate = new FeeRate($data);
        $this->ensureValid($rate, null);

        return $this->rates->loadDetail($this->withUniqueRate(fn () => $this->rates->create($data)));
    }

    /**
     * The rules run against the rate with the input applied, so a partial update that
     * sends only the group is still checked against the class.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(FeeRate $rate, array $data): FeeRate
    {
        $this->ensureValid((clone $rate)->fill($data), $rate->id);

        return $this->rates->loadDetail($this->withUniqueRate(fn () => $this->rates->update($rate, $data)));
    }

    public function delete(FeeRate $rate): void
    {
        abort_if($this->rates->hasDues($rate), 409, 'Fee rate is used by fee dues and cannot be deleted.');

        $this->rates->delete($rate);
    }

    private function ensureValid(FeeRate $rate, ?int $exceptId): void
    {
        $class = $this->classes->findOrFail($rate->class_id);

        if ($rate->group !== null && ! $class->hasGroups()) {
            throw ValidationException::withMessages(['group' => ['A group is only allowed for Class 9 and above.']]);
        }

        if ($this->rates->existsFor($rate->fee_head_id, $rate->class_id, $rate->academic_year_id, $rate->group, $exceptId)) {
            throw $this->duplicate();
        }
    }

    /**
     * @see SubjectService::withUniqueCode()
     */
    private function withUniqueRate(callable $write): FeeRate
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            if (UniqueViolation::is($e, 'fee_rates', ['fee_head_id', 'class_id', 'academic_year_id', 'group'], 'fee_rates_head_class_year_group_unique')) {
                throw $this->duplicate();
            }

            throw $e;
        }
    }

    private function duplicate(): ValidationException
    {
        return ValidationException::withMessages([
            'fee_head_id' => ['A rate for this fee head, class, year and group already exists.'],
        ]);
    }
}
