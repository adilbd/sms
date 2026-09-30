<?php

namespace App\Services;

use App\Models\Shift;
use App\Repositories\Contracts\ShiftRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class ShiftService
{
    public function __construct(private ShiftRepositoryInterface $shifts) {}

    /**
     * @param  array{search?: string, is_active?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->shifts->paginate($filters, $perPage);
    }

    public function create(array $data): Shift
    {
        return $this->shifts->create($data);
    }

    public function update(Shift $shift, array $data): Shift
    {
        return $this->shifts->update($shift, $data);
    }

    public function delete(Shift $shift): void
    {
        abort_if($this->shifts->hasStaff($shift), 409, 'Shift has staff assigned and cannot be deleted.');

        $this->shifts->delete($shift);
    }

    /**
     * Active shifts, ordered for display. The public shift filter pills only appear
     * when there is more than one (see Web\StaffController).
     */
    public function activeShifts(): Collection
    {
        return $this->shifts->activeOrdered();
    }

    public function findActiveBySlug(string $slug): ?Shift
    {
        $shift = $this->shifts->findBySlug($slug);

        return ($shift && $shift->is_active) ? $shift : null;
    }
}
