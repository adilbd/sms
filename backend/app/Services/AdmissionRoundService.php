<?php

namespace App\Services;

use App\Models\AdmissionRound;
use App\Repositories\Contracts\AdmissionRoundRepositoryInterface;
use App\Support\PostBody;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admission rounds: an academic year's window for online applications, with the classes
 * and seats on offer. The classes are written together with the round (`classes[]`).
 * See docs/tasks/online-admission.md.
 */
class AdmissionRoundService
{
    public function __construct(private AdmissionRoundRepositoryInterface $rounds) {}

    /**
     * @param  array{academic_year_id?: mixed, is_published?: mixed, search?: string}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->rounds->paginate($filters, $perPage);
    }

    public function find(AdmissionRound $round): AdmissionRound
    {
        return $this->rounds->loadDetail($round);
    }

    /**
     * @param  array<string, mixed>  $data  Round fields plus `classes` (class_id, seats).
     */
    public function create(array $data): AdmissionRound
    {
        $classes = $data['classes'];
        $attributes = $this->sanitized(collect($data)->except('classes')->all());

        $round = new AdmissionRound($attributes);
        $this->ensureHasName($round);
        $this->ensureWindowIsValid($round);

        return DB::transaction(function () use ($attributes, $classes) {
            $round = $this->rounds->create($attributes);
            $this->rounds->syncClasses($round, $classes);

            return $this->rounds->loadDetail($round);
        });
    }

    /**
     * The name and window rules run against the round with the input applied, so a
     * partial update that sends one date is still checked against the other.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(AdmissionRound $round, array $data): AdmissionRound
    {
        $classes = $data['classes'] ?? null;
        $attributes = $this->sanitized(collect($data)->except('classes')->all());

        $merged = (clone $round)->fill($attributes);
        $this->ensureHasName($merged);
        $this->ensureWindowIsValid($merged);

        // Application numbers carry the year, and applications hold the round's classes.
        if (isset($attributes['academic_year_id'])
            && (int) $attributes['academic_year_id'] !== (int) $round->academic_year_id
            && $this->rounds->hasApplications($round)) {
            throw ValidationException::withMessages([
                'academic_year_id' => ['The academic year cannot change once the round has applications.'],
            ]);
        }

        if ($classes !== null) {
            $this->ensureNoAppliedClassIsDropped($round, $classes);
        }

        return DB::transaction(function () use ($round, $attributes, $classes) {
            $round = $this->rounds->update($round, $attributes);

            if ($classes !== null) {
                $this->rounds->syncClasses($round, $classes);
            }

            return $this->rounds->loadDetail($round);
        });
    }

    public function delete(AdmissionRound $round): void
    {
        // Soft deletes bypass the foreign key, so check the references here (see
        // SubjectService::delete()).
        abort_if($this->rounds->hasApplications($round), 409, 'The round has applications and cannot be deleted.');

        $this->rounds->delete($round);
    }

    /**
     * The instructions are rich text from the admin editor, stored as sanitized HTML; an
     * empty result is stored as null.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function sanitized(array $attributes): array
    {
        foreach (['instructions_bn', 'instructions_en'] as $field) {
            if (array_key_exists($field, $attributes)) {
                $clean = filled($attributes[$field]) ? trim(PostBody::sanitize((string) $attributes[$field])) : '';
                $attributes[$field] = PostBody::isEmpty($clean) ? null : $clean;
            }
        }

        return $attributes;
    }

    private function ensureHasName(AdmissionRound $round): void
    {
        if (blank($round->name_en) && blank($round->name_bn)) {
            throw ValidationException::withMessages([
                'name_en' => ['Enter the round name in English or Bangla.'],
            ]);
        }
    }

    private function ensureWindowIsValid(AdmissionRound $round): void
    {
        if ($round->opens_at && $round->closes_at && $round->closes_at->lt($round->opens_at)) {
            throw ValidationException::withMessages([
                'closes_at' => ['The closing date must be on or after the opening date.'],
            ]);
        }
    }

    /**
     * @param  list<array{class_id: int}>  $classes
     */
    private function ensureNoAppliedClassIsDropped(AdmissionRound $round, array $classes): void
    {
        $kept = array_map(fn (array $row) => (int) $row['class_id'], $classes);
        $dropped = array_diff($this->rounds->classIdsWithApplications($round), $kept);

        if ($dropped !== []) {
            throw ValidationException::withMessages([
                'classes' => ['A class that already has applications cannot be removed from the round.'],
            ]);
        }
    }
}
