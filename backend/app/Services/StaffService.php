<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\Staff;
use App\Repositories\Contracts\ShiftRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Admin CRUD for staff (current and former teaching/non-teaching staff), plus the
 * publicList()/publicFind() reads shared by the Blade site (Web\StaffController) and
 * /api/public/staff (Api\PublicContentController), so both always agree. See
 * docs/architecture-guidelines.md; mirrors Gallery/InstituteSettingsService for child
 * rows and photo handling.
 */
class StaffService
{
    public function __construct(
        private StaffRepositoryInterface $staff,
        private ShiftRepositoryInterface $shifts,
    ) {}

    /**
     * @param  array{search?: string, category?: string, position?: string, is_active?: mixed, shift?: mixed}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->staff->paginate($filters, $perPage);
    }

    public function find(Staff $staff): Staff
    {
        return $staff->load(['shifts', 'educations', 'trainings']);
    }

    public function create(array $data, ?UploadedFile $photo): Staff
    {
        [$data, $shiftIds, $educations, $trainings] = $this->extractChildData($data);

        // A new model carries the column defaults (status=active, ...), so an omitted
        // status is still checked (see SubjectService::create() for the same pattern).
        $staff = new Staff($data);
        $this->ensureAtLeastOneName($staff);
        $this->ensureLeavingDateRules($staff);
        $this->ensureUniqueHeadPerShift($shiftIds, $staff->position, $staff->status, null);

        $photoPath = null;
        if ($photo) {
            $data['photo'] = $photoPath = $this->storePhoto($photo);
        }

        try {
            return DB::transaction(function () use ($data, $shiftIds, $educations, $trainings) {
                $staff = $this->staff->create($data);
                $this->staff->syncShifts($staff, $shiftIds);
                $this->staff->syncEducations($staff, $educations);
                $this->staff->syncTrainings($staff, $trainings);

                return $staff->load(['shifts', 'educations', 'trainings']);
            });
        } catch (Throwable $e) {
            // The row was never saved, so a newly uploaded photo would otherwise be
            // orphaned on disk (see InstituteSettingsService::update()).
            if ($photoPath) {
                Storage::disk('public')->delete($photoPath);
            }

            throw $e;
        }
    }

    public function update(Staff $staff, array $data, ?UploadedFile $photo, bool $removePhoto): Staff
    {
        [$data, $shiftIds, $educations, $trainings] = $this->extractChildData($data, forUpdate: true);

        // Checked against the model with the input applied, so a partial update that
        // only sends one of the related fields is still validated (see SubjectService::
        // update() for the same pattern).
        $merged = (clone $staff)->fill($data);
        $this->ensureAtLeastOneName($merged);
        $this->ensureLeavingDateRules($merged);

        $effectiveShiftIds = $shiftIds ?? $staff->shifts()->pluck('shifts.id')->all();
        $this->ensureUniqueHeadPerShift($effectiveShiftIds, $merged->position, $merged->status, $staff->id);

        $oldPhotoPath = $staff->photo;
        $newPhotoPath = null;

        if ($photo) {
            $data['photo'] = $newPhotoPath = $this->storePhoto($photo);
        } elseif ($removePhoto) {
            $data['photo'] = null;
        }

        try {
            $staff = DB::transaction(function () use ($staff, $data, $shiftIds, $educations, $trainings, $oldPhotoPath, $newPhotoPath, $removePhoto) {
                $staff = $this->staff->update($staff, $data);

                if ($shiftIds !== null) {
                    $this->staff->syncShifts($staff, $shiftIds);
                }

                if ($educations !== null) {
                    $this->staff->syncEducations($staff, $educations);
                }

                if ($trainings !== null) {
                    $this->staff->syncTrainings($staff, $trainings);
                }

                // Deferred with afterCommit() so a rolled-back transaction never
                // deletes a file whose row still points at it.
                DB::afterCommit(function () use ($oldPhotoPath, $newPhotoPath, $removePhoto) {
                    if ($oldPhotoPath && ($newPhotoPath || $removePhoto)) {
                        Storage::disk('public')->delete($oldPhotoPath);
                    }
                });

                return $staff;
            });
        } catch (Throwable $e) {
            if ($newPhotoPath) {
                Storage::disk('public')->delete($newPhotoPath);
            }

            throw $e;
        }

        return $staff->load(['shifts', 'educations', 'trainings']);
    }

    public function delete(Staff $staff): void
    {
        $this->staff->delete($staff);
    }

    /**
     * @param  array{position?: string, former?: bool, shift?: string}  $filters
     */
    public function publicList(array $filters): Collection
    {
        $repoFilters = [
            'position' => $filters['position'] ?? null,
            'former' => array_key_exists('former', $filters) ? $filters['former'] : null,
        ];

        if (filled($filters['shift'] ?? null)) {
            $repoFilters['shift_id'] = $this->resolveActiveShift($filters['shift'])->id;
        }

        return $this->staff->publicList($repoFilters);
    }

    public function publicFind(int $id): Staff
    {
        return $this->staff->findPublished($id);
    }

    public function publishedForSitemap(): Collection
    {
        return $this->staff->publishedForSitemap();
    }

    /**
     * Resolves a shift slug from a public list filter (?shift=slug) or aborts 404,
     * same as an unknown page/post slug. Shared by the web pages and /api/public/staff
     * so both agree on what counts as a valid shift.
     */
    public function resolveActiveShift(string $slug): Shift
    {
        $shift = $this->shifts->findBySlug($slug);

        abort_if(! $shift || ! $shift->is_active, 404);

        return $shift;
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?list<int>, 2: ?list<array<string, mixed>>, 3: ?list<array<string, mixed>>}
     */
    private function extractChildData(array $data, bool $forUpdate = false): array
    {
        $shiftIds = array_key_exists('shift_ids', $data) ? $data['shift_ids'] : ($forUpdate ? null : []);
        $educations = array_key_exists('educations', $data) ? $data['educations'] : ($forUpdate ? null : []);
        $trainings = array_key_exists('trainings', $data) ? $data['trainings'] : ($forUpdate ? null : []);

        unset($data['shift_ids'], $data['educations'], $data['trainings']);

        return [$data, $shiftIds, $educations, $trainings];
    }

    private function ensureAtLeastOneName(Staff $staff): void
    {
        if (! $staff->name_en && ! $staff->name_bn) {
            throw ValidationException::withMessages([
                'name_en' => ['Either the English or Bangla name is required.'],
            ]);
        }
    }

    private function ensureLeavingDateRules(Staff $staff): void
    {
        if ($staff->status !== Staff::STATUS_ACTIVE && ! $staff->leaving_date) {
            throw ValidationException::withMessages([
                'leaving_date' => ['The leaving date is required when the staff member is not active.'],
            ]);
        }

        if ($staff->leaving_date && $staff->joining_date && $staff->leaving_date->lt($staff->joining_date)) {
            throw ValidationException::withMessages([
                'leaving_date' => ['The leaving date must be on or after the joining date.'],
            ]);
        }
    }

    /**
     * Each shift can have at most one active head and one active assistant_head.
     *
     * @param  list<int>  $shiftIds
     */
    private function ensureUniqueHeadPerShift(array $shiftIds, string $position, string $status, ?int $exceptId): void
    {
        if ($status !== Staff::STATUS_ACTIVE) {
            return;
        }

        if (! in_array($position, [Staff::POSITION_HEAD, Staff::POSITION_ASSISTANT_HEAD], true)) {
            return;
        }

        foreach ($shiftIds as $shiftId) {
            if ($this->staff->hasActiveInPosition((int) $shiftId, $position, $exceptId)) {
                $label = $position === Staff::POSITION_HEAD ? 'head' : 'assistant head';

                throw ValidationException::withMessages([
                    'position' => ["This shift already has an active {$label}."],
                ]);
            }
        }
    }

    private function storePhoto(UploadedFile $file): string
    {
        return $file->storeAs('staff', Str::uuid().'.'.$file->extension(), 'public');
    }
}
