<?php

namespace App\Services;

use App\Models\FeeHead;
use App\Repositories\Contracts\FeeHeadRepositoryInterface;
use App\Support\UniqueViolation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

/**
 * Fee heads (Tuition, Session, Exam fee, Admission). A head is monthly, one_time or
 * per_exam; its amounts are FeeRates per class and year. A head with rates or dues can't
 * be deleted, and its kind can't change once it has dues.
 */
class FeeHeadService
{
    public function __construct(private FeeHeadRepositoryInterface $heads) {}

    /**
     * @param  array{search?: string, kind?: string, is_active?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->heads->paginate($filters, $perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): FeeHead
    {
        $this->ensureHasName(new FeeHead($data));

        return $this->withUniqueCode(fn () => $this->heads->create($data));
    }

    /**
     * The name rule runs against the head with the input applied, so a partial update that
     * clears one name is still checked against the other.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(FeeHead $head, array $data): FeeHead
    {
        $this->ensureHasName((clone $head)->fill($data));

        if (isset($data['kind']) && $data['kind'] !== $head->kind && $this->heads->hasDues($head)) {
            throw ValidationException::withMessages(['kind' => ['The kind can not change once the head has dues.']]);
        }

        return $this->withUniqueCode(fn () => $this->heads->update($head, $data));
    }

    public function delete(FeeHead $head): void
    {
        // Foreign keys don't protect a soft-deleted head, so check the references here.
        abort_if($this->heads->hasRates($head), 409, 'Fee head has rates and cannot be deleted.');
        abort_if($this->heads->hasDues($head), 409, 'Fee head has dues and cannot be deleted.');

        $this->heads->delete($head);
    }

    private function ensureHasName(FeeHead $head): void
    {
        if (blank($head->name_en) && blank($head->name_bn)) {
            throw ValidationException::withMessages([
                'name_en' => ['Enter the fee head name in English or Bangla.'],
            ]);
        }
    }

    /**
     * The uniqueness rule runs before the write, so a concurrent request can still hit the
     * database index (see SubjectService::withUniqueCode()).
     */
    private function withUniqueCode(callable $write): FeeHead
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            if (UniqueViolation::is($e, 'fee_heads', ['code'])) {
                throw ValidationException::withMessages(['code' => ['The code has already been taken.']]);
            }

            throw $e;
        }
    }
}
