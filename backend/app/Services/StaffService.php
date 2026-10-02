<?php

namespace App\Services;

use App\Models\Shift;
use App\Models\Staff;
use App\Models\User;
use App\Repositories\Contracts\ShiftRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\LoginTrust;
use App\Support\Mobile;
use App\Support\UniqueViolation;
use App\Support\Username;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
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
 * rows and photo handling. A staff member can also be given a login (the payload's
 * `login` block, see syncLogin()): teachers get the `teacher` role, non-teaching staff
 * the `office` role, the username is the employee ID, and leaving or turning the login
 * off deactivates the user and revokes its tokens (the same pattern as StudentService).
 */
class StaffService
{
    public function __construct(
        private StaffRepositoryInterface $staff,
        private ShiftRepositoryInterface $shifts,
        private UserRepositoryInterface $users,
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
        return $staff->load(['shifts', 'educations', 'trainings', 'user.roles']);
    }

    public function create(array $data, ?UploadedFile $photo): Staff
    {
        [$data, $shiftIds, $educations, $trainings] = $this->extractChildData($data);
        [$data, $login] = $this->extractLogin($data);

        // A new model carries the column defaults (status=active, ...), so an omitted
        // status is still checked (see SubjectService::create() for the same pattern).
        $staff = new Staff($data);
        $this->ensureAtLeastOneName($staff);
        $this->ensureCategoryMatchesPosition($staff);
        $this->ensureLeavingDateRules($staff);
        $this->ensureLoginRequest($staff, $login);

        $photoPath = null;
        if ($photo) {
            $data['photo'] = $photoPath = $this->storePhoto($photo);
        }

        try {
            return $this->withUniqueLogin(fn () => DB::transaction(function () use ($data, $shiftIds, $educations, $trainings, $staff, $login) {
                // Locks the affected shifts (inside ensureUniqueHeadPerShift()) before
                // checking the rule, and before the write, so a concurrent request
                // promoting another head/assistant_head for the same shift can't race
                // past this check.
                $this->ensureUniqueHeadPerShift($shiftIds, $staff->position, $staff->status, null);

                $staff = $this->staff->create($data);
                $this->staff->syncShifts($staff, $shiftIds);
                $this->staff->syncEducations($staff, $educations);
                $this->staff->syncTrainings($staff, $trainings);

                $staff = $this->syncLogin($staff, $login, null);

                return $staff->load(['shifts', 'educations', 'trainings', 'user.roles']);
            }));
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
        [$data, $login] = $this->extractLogin($data);

        // Checked against the model with the input applied, so a partial update that
        // only sends one of the related fields is still validated (see SubjectService::
        // update() for the same pattern).
        $merged = (clone $staff)->fill($data);
        $this->ensureAtLeastOneName($merged);
        $this->ensureCategoryMatchesPosition($merged);
        $this->ensureLeavingDateRules($merged);
        $this->ensureLoginRequest($merged, $login, requireEmployeeId: $login === null && $staff->user_id !== null);

        $oldPhotoPath = $staff->photo;
        $newPhotoPath = null;

        if ($photo) {
            $data['photo'] = $newPhotoPath = $this->storePhoto($photo);
        } elseif ($removePhoto) {
            $data['photo'] = null;
        }

        try {
            $staff = $this->withUniqueLogin(fn () => DB::transaction(function () use ($staff, $data, $shiftIds, $educations, $trainings, $merged, $oldPhotoPath, $newPhotoPath, $removePhoto, $login) {
                // Read this staff member's current shifts (when none were sent).
                // shiftIdsFor() takes a locking read of its own (see StaffRepository),
                // but that alone wouldn't be enough: what actually guarantees
                // correctness is that ensureUniqueHeadPerShift() below locks the
                // affected shifts and then checks hasActiveInPosition() with a locking
                // read too, which always sees the latest committed data regardless of
                // when this transaction's snapshot was taken (InnoDB REPEATABLE READ
                // fixes a plain read's snapshot at the transaction's first read, but a
                // locking read always reads the newest committed row version instead).
                $effectiveShiftIds = $shiftIds ?? $this->staff->shiftIdsFor($staff);
                $this->ensureUniqueHeadPerShift($effectiveShiftIds, $merged->position, $merged->status, $staff->id);

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

                $staff = $this->syncLogin($staff, $login);

                // Deferred with afterCommit() so a rolled-back transaction never
                // deletes a file whose row still points at it.
                DB::afterCommit(function () use ($oldPhotoPath, $newPhotoPath, $removePhoto) {
                    if ($oldPhotoPath && ($newPhotoPath || $removePhoto)) {
                        Storage::disk('public')->delete($oldPhotoPath);
                    }
                });

                return $staff;
            }));
        } catch (Throwable $e) {
            if ($newPhotoPath) {
                Storage::disk('public')->delete($newPhotoPath);
            }

            throw $e;
        }

        return $staff->load(['shifts', 'educations', 'trainings', 'user.roles']);
    }

    public function delete(Staff $staff): void
    {
        // Foreign keys don't protect soft-deleted rows, so check the reference here
        // (see SubjectService::delete() for the same pattern).
        abort_if(
            $this->staff->hasSubjectAssignments($staff),
            409,
            'Staff member is assigned to subjects and cannot be deleted.'
        );

        abort_if(
            $this->staff->isClassTeacher($staff),
            409,
            'Staff member is a class teacher and cannot be deleted.'
        );

        abort_if(
            $this->staff->isInRoutine($staff),
            409,
            'Staff member teaches in a class routine and cannot be deleted.'
        );

        DB::transaction(function () use ($staff) {
            // A deleted staff member can no longer sign in.
            if ($staff->user_id && $login = $this->users->find($staff->user_id)) {
                if ($this->isStaffLogin($login)) {
                    $this->deactivate($login);
                }
            }

            $this->staff->delete($staff);
        });
    }

    /**
     * $filters['shift_id'] takes priority over $filters['shift'] when both are given,
     * so a caller that already resolved the Shift (Web\StaffController, to 404 on an
     * unknown slug and show it in the view) passes the id straight through instead of
     * making resolveActiveShift() look the same slug up a second time.
     *
     * @param  array{position?: string, former?: bool, shift?: string, shift_id?: int}  $filters
     * @param  bool  $withFullProfile  See StaffRepositoryInterface::publicList().
     */
    public function publicList(array $filters, bool $withFullProfile = false): Collection
    {
        $repoFilters = [
            'position' => $filters['position'] ?? null,
            'former' => array_key_exists('former', $filters) ? $filters['former'] : null,
        ];

        if (filled($filters['shift_id'] ?? null)) {
            $repoFilters['shift_id'] = $filters['shift_id'];
        } elseif (filled($filters['shift'] ?? null)) {
            $repoFilters['shift_id'] = $this->resolveActiveShift($filters['shift'])->id;
        }

        return $this->staff->publicList($repoFilters, $withFullProfile);
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
        // A present-but-null value (StaffForm.vue sends an explicit empty string for
        // "educations"/"trainings" when clearing every row, since a multipart request
        // can't express an empty array by omitting the field — see the "nullable" rule
        // in HasStaffChildRules) means "sync to zero rows", not "field wasn't sent".
        $shiftIds = array_key_exists('shift_ids', $data) ? ($data['shift_ids'] ?? []) : ($forUpdate ? null : []);
        $educations = array_key_exists('educations', $data) ? ($data['educations'] ?? []) : ($forUpdate ? null : []);
        $trainings = array_key_exists('trainings', $data) ? ($data['trainings'] ?? []) : ($forUpdate ? null : []);

        unset($data['shift_ids'], $data['educations'], $data['trainings']);

        return [$data, $shiftIds, $educations, $trainings];
    }

    /**
     * @return array{0: array<string, mixed>, 1: ?array{enabled: bool, role?: ?string, password?: ?string, email?: ?string}}
     */
    private function extractLogin(array $data): array
    {
        $login = $data['login'] ?? null;
        unset($data['login']);

        if (is_array($login)) {
            $login['enabled'] = filter_var($login['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            // The admin's switch is remembered on the staff row (staff.login_enabled).
            $data['login_enabled'] = $login['enabled'];
        } else {
            $login = null;
        }

        return [$data, $login];
    }

    /**
     * The `login` block's rules that need only the staff row with the input applied: a
     * login needs an employee ID and a role that fits the category. (Whether the username
     * or email is free, and whether a password is needed, depend on the saved users and
     * are checked in syncLogin().) $requireEmployeeId is set when the member already has
     * a login, which a blank employee ID would leave without a username.
     *
     * @param  ?array{enabled: bool, role?: ?string}  $login
     */
    private function ensureLoginRequest(Staff $staff, ?array $login, bool $requireEmployeeId = false): void
    {
        $enabling = $login !== null && $login['enabled'];

        if (($enabling || $requireEmployeeId) && blank($staff->employee_id)) {
            throw ValidationException::withMessages([
                'employee_id' => ['An employee ID is required because it is the staff member\'s login username.'],
            ]);
        }

        // The sign-in form also resolves a mobile number or an email, so a username shaped
        // like either could match a different account than the one it belongs to.
        if (($enabling || $requireEmployeeId) && (
            str_contains((string) $staff->employee_id, '@')
            || Mobile::isValid((string) Mobile::normalize($staff->employee_id))
        )) {
            throw ValidationException::withMessages([
                'employee_id' => ['The employee ID is the login username, so it cannot contain "@" or look like a mobile number.'],
            ]);
        }

        if ($enabling && ($login['role'] ?? null) !== $this->roleFor($staff)) {
            $expected = $this->roleFor($staff);

            throw ValidationException::withMessages([
                'login.role' => ["The role must be \"{$expected}\" for a staff member in the \"{$staff->category}\" category."],
            ]);
        }
    }

    private function roleFor(Staff $staff): string
    {
        return $staff->category === Staff::CATEGORY_TEACHER ? 'teacher' : 'office';
    }

    /**
     * Whether $user is a login created for staff (teacher/office roles, or none yet), as
     * opposed to a student, guardian or admin account that must not be repurposed. A
     * role-less user counts as a staff login only because it is reachable solely through
     * staff.user_id (an admin links it there), never by a name or username lookup.
     */
    private function isStaffLogin(User $user): bool
    {
        return array_diff($this->users->roleNames($user), User::STAFF_LOGIN_ROLES) === [];
    }

    private function deactivate(User $user): void
    {
        $this->users->update($user, ['is_active' => false]);
        $this->users->revokeAllTokens($user);
        LoginTrust::invalidate($user);
    }

    /**
     * Keeps the staff member's login in step with the saved row, inside the caller's
     * transaction. With a `login` block: enabling creates the user (or reuses the staff
     * member's own, linked through `staff.user_id`) with username = lowercased employee
     * ID, the role for the category, the optional email and password; disabling
     * deactivates it. Without a block, an existing login still follows the row: name,
     * username (employee ID), role (category) and whether the staff member is active.
     * The user is active only while `staff.login_enabled` (the admin's switch, set from
     * the block's `enabled`) is on and the status is active; otherwise it is deactivated
     * and every token revoked.
     *
     * @param  ?array{enabled: bool, role?: ?string, password?: ?string, email?: ?string}  $login
     */
    private function syncLogin(Staff $staff, ?array $login): Staff
    {
        $user = $staff->user_id ? $this->users->find($staff->user_id) : null;
        $enabling = $login !== null && $login['enabled'];

        if ($user !== null && ! $this->isStaffLogin($user)) {
            if ($enabling) {
                throw ValidationException::withMessages([
                    'login.enabled' => ['This staff member is linked to a student, guardian or admin account, which cannot be used as a staff login.'],
                ]);
            }

            return $staff;
        }

        $active = $staff->status === Staff::STATUS_ACTIVE;
        $allowed = $active && $staff->login_enabled;
        $password = $login['password'] ?? null;

        if ($login !== null && ! $login['enabled']) {
            if ($user !== null) {
                $this->deactivate($user);
            }

            return $staff;
        }

        if ($user === null && ! $enabling) {
            return $staff;
        }

        $attributes = ['name' => $staff->name_en ?: $staff->name_bn];

        if (filled($staff->employee_id)) {
            $username = Username::normalize($staff->employee_id);

            if ($user === null || $user->username !== $username) {
                // The same resolution the sign-in form uses, so the username can't also
                // match another account's email or mobile number.
                $owner = $this->users->findForLogin($username);

                if ($owner !== null && $owner->id !== $user?->id) {
                    throw ValidationException::withMessages(['employee_id' => ['This employee ID is already used as another account\'s username.']]);
                }

                $attributes['username'] = $username;
            }
        }

        if ($login !== null && array_key_exists('email', $login)) {
            $email = filled($login['email']) ? Username::normalize($login['email']) : null;

            if ($email !== null) {
                $owner = $this->users->findForLogin($email);

                if ($owner !== null && $owner->id !== $user?->id) {
                    throw ValidationException::withMessages(['login.email' => ['The email has already been taken.']]);
                }
            }

            $attributes['email'] = $email;
        }

        $role = $this->roleFor($staff);

        if ($user === null) {
            if (blank($password)) {
                throw ValidationException::withMessages(['login.password' => ['A password is required to create the login.']]);
            }

            $user = $this->users->createWithRole($attributes + ['password' => $password, 'is_active' => $allowed], $role);

            return $this->staff->update($staff, ['user_id' => $user->id]);
        }

        // An existing login can sign in only while the admin has it switched on
        // (staff.login_enabled) and the member is active, so returning to active never
        // re-enables a login that was deliberately turned off.
        $attributes['is_active'] = $allowed;

        if (filled($password)) {
            $attributes['password'] = $password;
        }

        $this->users->update($user, $attributes);

        if (! in_array($role, $this->users->roleNames($user), true)) {
            $this->users->syncRole($user, $role);
        }

        if (filled($password) || ! $allowed) {
            $this->users->revokeAllTokens($user);
            LoginTrust::invalidate($user);
        }

        return $staff;
    }

    /**
     * The checks in syncLogin() run before the write, so a concurrent request can still
     * hit a users index. Report that as the validation error the user would have seen
     * (see SubjectService::withUniqueCode()).
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function withUniqueLogin(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            if (UniqueViolation::is($e, 'users', ['email'])) {
                throw ValidationException::withMessages(['login.email' => ['The email has already been taken.']]);
            }

            if (UniqueViolation::is($e, 'users', ['username'])) {
                throw ValidationException::withMessages(['employee_id' => ['This employee ID is already used as another account\'s username.']]);
            }

            throw $e;
        }
    }

    private function ensureAtLeastOneName(Staff $staff): void
    {
        if (! $staff->name_en && ! $staff->name_bn) {
            throw ValidationException::withMessages([
                'name_en' => ['Either the English or Bangla name is required.'],
            ]);
        }
    }

    /**
     * head/assistant_head/teacher are teaching positions (category=teacher); staff is
     * the only non-teaching position (category=staff).
     */
    private function ensureCategoryMatchesPosition(Staff $staff): void
    {
        $expected = $staff->position === Staff::POSITION_STAFF ? Staff::CATEGORY_STAFF : Staff::CATEGORY_TEACHER;

        if ($staff->category !== $expected) {
            throw ValidationException::withMessages([
                'category' => ["The category must be \"{$expected}\" for position \"{$staff->position}\"."],
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

        // Locks the shift rows for the rest of this transaction, so a concurrent
        // request that's also trying to promote a head/assistant_head into one of
        // these shifts has to wait for this one to commit (or roll back) instead of
        // reading the same "no active head yet" state and both succeeding.
        $this->shifts->lockForUpdate(array_map('intval', $shiftIds));

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
