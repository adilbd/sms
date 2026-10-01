<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\StudentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\LoginTrust;
use App\Support\UniqueViolation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Students, their logins and their enrolment. Creating a student also creates the
 * student's login (username = student ID) and links, or creates, the guardian's login
 * (username = guardian mobile), which siblings share. See docs/tasks/students-module.md;
 * mirrors StaffService for the name rule, leaving-date rules and photo handling.
 */
class StudentService
{
    public function __construct(
        private StudentRepositoryInterface $students,
        private UserRepositoryInterface $users,
        private AcademicYearRepositoryInterface $years,
        private EnrolmentService $enrolments,
    ) {}

    /**
     * Lists the students enrolled in $filters['academic_year_id'], which defaults to the
     * active year.
     *
     * @param  array{academic_year_id?: mixed, class_id?: mixed, section_id?: mixed, shift_id?: mixed, group?: string, status?: string, search?: string, search_sensitive?: bool, scope_section_ids?: list<int>}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        if (! filled($filters['academic_year_id'] ?? null)) {
            $filters['academic_year_id'] = $this->years->findActive()?->id;
        }

        return $this->students->paginate($filters, $perPage);
    }

    /**
     * 403 unless $student is enrolled, in $academicYearId (default: the active year), in
     * one of $sectionIds. A null $sectionIds means the caller isn't restricted (see
     * TeacherScope::sectionIdsFor()).
     *
     * @param  list<int>|null  $sectionIds
     */
    public function ensureInSections(Student $student, ?array $sectionIds, ?int $academicYearId = null): void
    {
        if ($sectionIds === null) {
            return;
        }

        $yearId = $academicYearId ?? $this->years->findActive()?->id;

        abort_if(
            $yearId === null || ! $this->students->isEnrolledInSections($student, $yearId, $sectionIds),
            403,
            'This student is not in one of your sections.'
        );
    }

    public function find(Student $student): Student
    {
        return $this->students->loadDetail($student, $this->years->findActive()?->id);
    }

    /**
     * Every enrolment of the student, newest year first.
     */
    public function enrolmentHistory(Student $student): Collection
    {
        return $this->enrolments->history($student);
    }

    /**
     * The signed-in student's own record.
     */
    public function findOwn(User $user): Student
    {
        $student = $this->students->findByUser($user, $this->years->findActive()?->id);

        abort_if($student === null, 404, 'No student record is linked to this account.');

        return $student;
    }

    /**
     * The students linked to the signed-in guardian's login.
     */
    public function childrenOf(User $guardian): Collection
    {
        return $this->students->childrenOf($guardian, $this->years->findActive()?->id);
    }

    /**
     * One of the signed-in guardian's own children. 403 for any other student, whether or
     * not it exists, so the response doesn't reveal which ids are taken.
     */
    public function findChildOf(User $guardian, int $studentId): Student
    {
        $child = $this->childrenOf($guardian)->firstWhere('id', $studentId);

        abort_if($child === null, 403, 'This student is not one of your children.');

        return $child;
    }

    /**
     * @param  array<string, mixed>  $data  Profile fields, `password`, `guardian_password` and `enrolment`.
     */
    public function create(array $data, ?UploadedFile $photo): Student
    {
        $password = (string) $data['password'];
        $guardianPassword = $data['guardian_password'] ?? null;
        $enrolment = $data['enrolment'];
        $profile = Arr::except($data, ['password', 'guardian_password', 'enrolment']);

        // A new model carries the column defaults (status=active), so an omitted status
        // is still checked (see SubjectService::create() for the same pattern).
        $student = new Student($profile);
        $this->ensureAtLeastOneName($student);
        $this->ensureLeavingDateRules($student);

        $year = $this->activeYear();

        $photoPath = null;
        if ($photo) {
            $profile['photo'] = $photoPath = $this->storePhoto($photo);
        }

        try {
            $created = $this->withUniqueFields(fn () => DB::transaction(function () use ($profile, $student, $password, $guardianPassword, $enrolment, $year) {
                $studentId = $this->students->nextStudentId($student->admission_date->year);
                $guardian = $this->resolveGuardian($profile['guardian_mobile'], $profile['guardian_name'], $guardianPassword);

                $login = $this->users->createWithRole([
                    'name' => $student->displayName(),
                    'username' => $studentId,
                    'email' => $profile['email'] ?? null,
                    'phone' => $profile['mobile'] ?? null,
                    'password' => $password,
                    'is_active' => $student->status === Student::STATUS_ACTIVE,
                ], 'student');

                $created = $this->students->create($profile + [
                    'student_id' => $studentId,
                    'user_id' => $login->id,
                    'guardian_user_id' => $guardian->id,
                ]);

                $this->enrolments->save($created, $year, $enrolment);
                $this->syncGuardianAccess($guardian);

                return $created;
            }));
        } catch (Throwable $e) {
            // The row was never saved, so a newly uploaded photo would otherwise be
            // orphaned on disk (see StaffService::create()).
            if ($photoPath) {
                Storage::disk('public')->delete($photoPath);
            }

            throw $e;
        }

        return $this->find($created);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Student $student, array $data, ?UploadedFile $photo, bool $removePhoto): Student
    {
        $password = $data['password'] ?? null;
        $guardianPassword = $data['guardian_password'] ?? null;
        $enrolment = $data['enrolment'] ?? null;
        $profile = Arr::except($data, ['password', 'guardian_password', 'enrolment']);

        // Checked against the model with the input applied, so a partial update that
        // only sends one of the related fields is still validated.
        $merged = (clone $student)->fill($profile);
        $this->ensureAtLeastOneName($merged);
        $this->ensureLeavingDateRules($merged);

        $year = $enrolment !== null ? $this->activeYear() : $this->years->findActive();

        $oldPhotoPath = $student->photo;
        $newPhotoPath = null;

        if ($photo) {
            $profile['photo'] = $newPhotoPath = $this->storePhoto($photo);
        } elseif ($removePhoto) {
            $profile['photo'] = null;
        }

        try {
            $updated = $this->withUniqueFields(fn () => DB::transaction(function () use ($student, $merged, $profile, $password, $guardianPassword, $enrolment, $year, $oldPhotoPath, $newPhotoPath, $removePhoto) {
                $previousGuardianId = $student->guardian_user_id;
                $guardianChanged = $previousGuardianId === null
                    || $merged->guardian_mobile !== $student->guardian_mobile;

                $student = $this->students->update($student, $profile);

                $this->syncStudentLogin($student, $password);

                if ($guardianChanged) {
                    $guardian = $this->resolveGuardian($student->guardian_mobile, $student->guardian_name, $guardianPassword);
                    $student = $this->students->update($student, ['guardian_user_id' => $guardian->id]);
                } else {
                    $guardian = $this->users->find((int) $student->guardian_user_id);

                    if ($guardian) {
                        $attributes = isset($profile['guardian_name']) && $guardian->name !== $profile['guardian_name']
                            ? ['name' => $profile['guardian_name']]
                            : [];

                        if (filled($guardianPassword)) {
                            $attributes['password'] = $guardianPassword;
                        }

                        if ($attributes !== []) {
                            $this->users->update($guardian, $attributes);
                        }

                        if (filled($guardianPassword)) {
                            $this->users->revokeAllTokens($guardian);
                            LoginTrust::invalidate($guardian);
                        }
                    }
                }

                if ($guardian) {
                    $this->syncGuardianAccess($guardian);
                }

                if ($previousGuardianId && $previousGuardianId !== $student->guardian_user_id) {
                    $this->syncGuardianAccessById($previousGuardianId);
                }

                if ($enrolment !== null) {
                    $this->enrolments->save($student, $year, $enrolment);
                } elseif ($year) {
                    $this->enrolments->syncStatus($student, $year);
                }

                // Deferred with afterCommit() so a rolled-back transaction never
                // deletes a file whose row still points at it.
                DB::afterCommit(function () use ($oldPhotoPath, $newPhotoPath, $removePhoto) {
                    if ($oldPhotoPath && ($newPhotoPath || $removePhoto)) {
                        Storage::disk('public')->delete($oldPhotoPath);
                    }
                });

                return $student;
            }));
        } catch (Throwable $e) {
            if ($newPhotoPath) {
                Storage::disk('public')->delete($newPhotoPath);
            }

            throw $e;
        }

        return $this->find($updated);
    }

    public function delete(Student $student): void
    {
        // Foreign keys don't protect soft-deleted rows, so check the references here
        // (see SubjectService::delete() for the same pattern).
        abort_if($this->students->hasAttendances($student), 409, 'Student has attendance records and cannot be deleted.');
        abort_if($this->students->hasExamMarks($student), 409, 'Student has exam marks and cannot be deleted.');
        abort_if($this->students->hasExamResults($student), 409, 'Student has exam results and cannot be deleted.');
        abort_if($this->students->hasFeeDuesOrPayments($student), 409, 'Student has fee dues or payments and cannot be deleted.');

        DB::transaction(function () use ($student) {
            if ($student->user_id && $login = $this->users->find($student->user_id)) {
                $this->users->update($login, ['is_active' => false]);
                $this->users->revokeAllTokens($login);
                LoginTrust::invalidate($login);
            }

            // The enrolment stays as history but frees its seat and roll number.
            if ($year = $this->years->findActive()) {
                $this->enrolments->release($student, $year);
            }

            // Deleted before the guardian check below so it no longer counts this student.
            $this->students->delete($student);

            if ($student->guardian_user_id) {
                $this->syncGuardianAccessById($student->guardian_user_id);
            }
        });
    }

    /**
     * Marks the student as left or graduated on $leavingDate: the same status change as an
     * edit (leaving-date rules, the student's login deactivated with its tokens revoked,
     * the guardian login switched off when no active child remains). Used by promotion,
     * inside the caller's transaction; the enrolment status is the caller's to sync.
     */
    public function changeStatus(Student $student, string $status, string $leavingDate): Student
    {
        $attributes = ['status' => $status, 'leaving_date' => $leavingDate];
        $this->ensureLeavingDateRules((clone $student)->fill($attributes));

        return DB::transaction(function () use ($student, $attributes) {
            $student = $this->students->update($student, $attributes);

            $this->syncStudentLogin($student, null);

            if ($student->guardian_user_id) {
                $this->syncGuardianAccessById($student->guardian_user_id);
            }

            return $student;
        });
    }

    private function activeYear(): AcademicYear
    {
        $year = $this->years->findActive();

        if (! $year) {
            throw ValidationException::withMessages([
                'enrolment.section_id' => ['There is no active academic year to enrol the student in.'],
            ]);
        }

        return $year;
    }

    /**
     * The guardian login for a mobile number: the existing parent user that owns it, or
     * a new one (which then needs a password).
     */
    private function resolveGuardian(string $mobile, string $name, ?string $password): User
    {
        $user = $this->users->findByUsername($mobile);

        if ($user === null) {
            if (blank($password)) {
                throw ValidationException::withMessages([
                    'guardian_password' => ['A guardian password is required because this mobile number has no guardian login yet.'],
                ]);
            }

            return $this->users->createWithRole([
                'name' => $name,
                'username' => $mobile,
                'phone' => $mobile,
                'password' => $password,
                'is_active' => true,
            ], 'parent');
        }

        if (! $this->users->hasRole($user, 'parent')) {
            throw ValidationException::withMessages([
                'guardian_mobile' => ['This mobile number already belongs to another account.'],
            ]);
        }

        // A deactivated guardian login belongs to a number that may since have been
        // recycled to someone else, so it is never revived under its old password.
        if (! $user->is_active && blank($password)) {
            throw ValidationException::withMessages([
                'guardian_password' => ['A new guardian password is required because the existing guardian login for this mobile number is deactivated.'],
            ]);
        }

        // The login carries the latest guardian name written for it.
        $attributes = $user->name !== $name ? ['name' => $name] : [];

        if (filled($password)) {
            $attributes['password'] = $password;
        }

        if ($attributes !== []) {
            $this->users->update($user, $attributes);
        }

        if (filled($password)) {
            $this->users->revokeAllTokens($user);
            LoginTrust::invalidate($user);
        }

        return $user;
    }

    /**
     * Keeps the student's login in step with the profile: name, email, mobile, whether
     * the student is still active, and (when given) a new password.
     */
    private function syncStudentLogin(Student $student, ?string $password): void
    {
        $login = $student->user_id ? $this->users->find($student->user_id) : null;

        if (! $login) {
            return;
        }

        $attributes = [
            'name' => $student->displayName(),
            'email' => $student->email,
            'phone' => $student->mobile,
            'is_active' => $student->status === Student::STATUS_ACTIVE,
        ];

        if (filled($password)) {
            $attributes['password'] = $password;
        }

        $this->users->update($login, $attributes);

        if (filled($password) || ! $attributes['is_active']) {
            $this->users->revokeAllTokens($login);
            LoginTrust::invalidate($login);
        }
    }

    /**
     * A guardian login is active while at least one of its students is (siblings share
     * it), and is turned off when the last one leaves, is deleted or moves elsewhere.
     */
    private function syncGuardianAccess(User $guardian): void
    {
        $active = $this->students->hasActiveChildren($guardian);

        if ($guardian->is_active !== $active) {
            $this->users->update($guardian, ['is_active' => $active]);
        }

        if (! $active) {
            $this->users->revokeAllTokens($guardian);
            LoginTrust::invalidate($guardian);
        }
    }

    private function syncGuardianAccessById(int $guardianId): void
    {
        if ($guardian = $this->users->find($guardianId)) {
            $this->syncGuardianAccess($guardian);
        }
    }

    private function ensureAtLeastOneName(Student $student): void
    {
        if (! $student->name_en && ! $student->name_bn) {
            throw ValidationException::withMessages([
                'name_en' => ['Either the English or Bangla name is required.'],
            ]);
        }
    }

    private function ensureLeavingDateRules(Student $student): void
    {
        if ($errors = $this->leavingDateErrors($student)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The leaving-date rules for the student with the new status and date applied, as
     * validation messages keyed by field (empty when they hold). Promotion uses it to
     * check leavers before its first write.
     *
     * @return array<string, list<string>>
     */
    public function leavingDateErrors(Student $student): array
    {
        if ($student->status !== Student::STATUS_ACTIVE && ! $student->leaving_date) {
            return ['leaving_date' => ['The leaving date is required when the student is not active.']];
        }

        if ($student->leaving_date && $student->admission_date && $student->leaving_date->lt($student->admission_date)) {
            return ['leaving_date' => ['The leaving date must be on or after the admission date.']];
        }

        return [];
    }

    /**
     * The unique rules run before the write, so a concurrent request can still hit a
     * database index. Report that as the validation error the user would have seen (see
     * SubjectService::withUniqueCode()).
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function withUniqueFields(callable $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            if (UniqueViolation::is($e, 'students', ['birth_registration_number'])) {
                throw ValidationException::withMessages([
                    'birth_registration_number' => ['The birth registration number has already been taken.'],
                ]);
            }

            if (UniqueViolation::is($e, 'users', ['email'])) {
                throw ValidationException::withMessages(['email' => ['The email has already been taken.']]);
            }

            // student_id / username: two requests allocated the same next number.
            if (UniqueViolation::is($e, 'students', ['student_id']) || UniqueViolation::is($e, 'users', ['username'])) {
                throw ValidationException::withMessages([
                    'student_id' => ['Could not allocate a unique student ID. Please try again.'],
                ]);
            }

            throw $e;
        }
    }

    private function storePhoto(UploadedFile $file): string
    {
        return $file->storeAs('students', Str::uuid().'.'.$file->extension(), 'public');
    }
}
