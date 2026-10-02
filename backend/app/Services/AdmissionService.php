<?php

namespace App\Services;

use App\Exceptions\AdmissionApplicationNotFoundException;
use App\Models\AdmissionApplication;
use App\Models\AdmissionRound;
use App\Repositories\Contracts\AdmissionApplicationRepositoryInterface;
use App\Repositories\Contracts\AdmissionRoundRepositoryInterface;
use App\Repositories\Contracts\ShiftRepositoryInterface;
use App\Support\UniqueViolation;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The public side of online admission: the open rounds, submitting an application, and
 * looking an application's status up. The Blade site (Web\AdmissionController) and the
 * mobile API (/api/public/admission-*) both go through this service, so they always
 * agree. Admin review lives in AdmissionApplicationService. See
 * docs/tasks/online-admission.md.
 *
 * Files go on the private `local` disk (storage/app/private/admissions) and are never
 * public URLs. Application numbers are `ADM-{year}-{000001}`, the year being the round's
 * academic year, from a locked counter that is retried on deadlock like fee receipts.
 */
class AdmissionService
{
    /** The private disk applications' files are stored on. */
    public const DISK = 'local';

    /** Saved applications allowed per IP per hour (generous: mobile networks in Bangladesh share IPs via CGNAT). */
    public const MAX_SUBMISSIONS = 10;

    /** Failed status lookups allowed per IP per hour before it is blocked. */
    public const MAX_STATUS_FAILURES = 10;

    public const DUPLICATE_MESSAGE = 'এই জন্ম নিবন্ধন নম্বর দিয়ে এই ভর্তি চক্রে ইতিমধ্যে আবেদন করা হয়েছে। (An application with this birth registration number already exists in this admission round.)';

    public function __construct(
        private AdmissionRoundRepositoryInterface $rounds,
        private AdmissionApplicationRepositoryInterface $applications,
        private ShiftRepositoryInterface $shifts,
    ) {}

    /**
     * The rounds families can apply to today.
     */
    public function openRounds(): Collection
    {
        return $this->rounds->open();
    }

    /**
     * The active shifts, for the form's optional preferred shift.
     */
    public function activeShifts(): Collection
    {
        return $this->shifts->activeOrdered();
    }

    /**
     * The application just submitted in this session, with its round and class for the
     * confirmation page, or null.
     */
    public function findSubmitted(int $id): ?AdmissionApplication
    {
        return $this->applications->findWithRoundAndClass($id);
    }

    /**
     * 404 unless the round is published and today (in Dhaka) is inside its window.
     */
    public function findOpenRound(int $id): AdmissionRound
    {
        $round = $this->rounds->findOpen($id);

        abort_if($round === null, 404);

        return $round;
    }

    /**
     * Saves an application for an open round. The class must be offered in the round, a
     * group is required from Class 9 and not allowed below it, and a child can apply once
     * per round (birth registration number). All three files are stored first and deleted
     * again if the row is not saved.
     *
     * @param  array<string, mixed>  $data  Validated fields (see SubmitApplicationRequest) without the files.
     * @param  array{photo: UploadedFile, birth_certificate?: ?UploadedFile, previous_school_doc?: ?UploadedFile}  $files
     */
    public function submit(int $roundId, array $data, array $files, string $ip): AdmissionApplication
    {
        $submissionKey = 'admission-submit:'.sha1($ip);

        if (RateLimiter::tooManyAttempts($submissionKey, self::MAX_SUBMISSIONS)) {
            throw new ThrottleRequestsException(
                'এই নেটওয়ার্ক থেকে অনেকগুলো আবেদন জমা হয়েছে। কিছুক্ষণ পরে আবার চেষ্টা করুন। (Too many applications from this network. Please try again later.)',
                null,
                ['Retry-After' => (string) RateLimiter::availableIn($submissionKey)],
            );
        }

        $round = $this->findOpenRound($roundId);

        $roundClass = $round->classes->firstWhere('class_id', (int) $data['class_id']);

        if ($roundClass === null) {
            throw ValidationException::withMessages([
                'class_id' => ['এই শ্রেণিতে এই ভর্তি চক্রে আবেদন করা যাচ্ছে না। (This class is not open for applications in this round.)'],
            ]);
        }

        $this->ensureGroupFits($roundClass->class->hasGroups(), $data['group'] ?? null);

        if ($this->applications->existsForBirthRegistration($round->id, $data['birth_registration_number'])) {
            throw $this->duplicate();
        }

        $paths = [];
        $attributes = $data;

        // The column is NOT NULL with a default, so an omitted nationality keeps it.
        if (blank($attributes['nationality'] ?? null)) {
            unset($attributes['nationality']);
        }

        foreach (['photo' => 'photo_path', 'birth_certificate' => 'birth_certificate_path', 'previous_school_doc' => 'previous_school_doc_path'] as $kind => $column) {
            $file = $files[$kind] ?? null;
            $attributes[$column] = $file ? $paths[] = $this->storeFile($file) : null;
        }

        $attributes += [
            'round_id' => $round->id,
            'status' => AdmissionApplication::STATUS_SUBMITTED,
            'submitted_ip_hash' => sha1($ip),
        ];

        $year = $round->academicYear->year;

        try {
            // Two submissions' first number of a year can deadlock on the counter row's gap
            // lock (MySQL REPEATABLE READ), so Laravel retries the transaction up to 3
            // times. The closure only touches the database, so a retry is safe.
            $application = DB::transaction(function () use ($year, $attributes) {
                $number = $this->applications->nextApplicationNumber($year);

                return $this->applications->create($attributes + [
                    'application_no' => sprintf('ADM-%d-%06d', $year, $number),
                ]);
            }, 3);

            // Only saved applications count, so correcting form errors never locks a
            // family out.
            RateLimiter::hit($submissionKey, 3600);

            return $application;
        } catch (UniqueConstraintViolationException $e) {
            // The pre-check can lose a race with a concurrent identical submission.
            $this->deleteFiles($paths);

            if (UniqueViolation::is($e, 'admission_applications', ['round_id', 'birth_registration_number'], 'admission_apps_round_birth_reg_unique')) {
                throw $this->duplicate();
            }

            throw $e;
        } catch (Throwable $e) {
            $this->deleteFiles($paths);

            throw $e;
        }
    }

    /**
     * The application matching the number and date of birth. Every miss (unknown number,
     * wrong date, soft-deleted) throws the same AdmissionApplicationNotFoundException,
     * and each one counts against the caller's IP: 10 misses an hour block further
     * lookups, as for the public result lookup.
     *
     * @param  array{application_no: string, date_of_birth: string}  $input
     */
    public function lookupStatus(array $input, string $ip): AdmissionApplication
    {
        $key = self::statusFailureKey($ip);

        if (RateLimiter::tooManyAttempts($key, self::MAX_STATUS_FAILURES)) {
            throw new ThrottleRequestsException(
                'অনেকবার অনুসন্ধান করা হয়েছে। কিছুক্ষণ পরে আবার চেষ্টা করুন। (Too many lookups. Please try again later.)',
                null,
                ['Retry-After' => (string) RateLimiter::availableIn($key)],
            );
        }

        $application = $this->applications->findForStatusLookup(
            Str::upper(trim($input['application_no'])),
            $input['date_of_birth'],
        );

        if ($application === null) {
            RateLimiter::hit($key, 3600);

            throw new AdmissionApplicationNotFoundException;
        }

        return $application;
    }

    public static function statusFailureKey(string $ip): string
    {
        return 'admission-status-fail:'.sha1($ip);
    }

    private function ensureGroupFits(bool $classHasGroups, ?string $group): void
    {
        if ($classHasGroups && $group === null) {
            throw ValidationException::withMessages([
                'group' => ['নবম শ্রেণি ও তার উপরে গ্রুপ বেছে নিতে হবে। (A group is required from Class 9.)'],
            ]);
        }

        if (! $classHasGroups && $group !== null) {
            throw ValidationException::withMessages([
                'group' => ['এই শ্রেণিতে কোনো গ্রুপ নেই। (This class has no groups.)'],
            ]);
        }
    }

    private function duplicate(): ValidationException
    {
        return ValidationException::withMessages(['birth_registration_number' => [self::DUPLICATE_MESSAGE]]);
    }

    private function storeFile(UploadedFile $file): string
    {
        return $file->storeAs('admissions', Str::uuid().'.'.$file->extension(), self::DISK);
    }

    /**
     * @param  list<string>  $paths
     */
    private function deleteFiles(array $paths): void
    {
        Storage::disk(self::DISK)->delete($paths);
    }
}
