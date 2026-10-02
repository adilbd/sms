<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\AdmissionApplicationRepositoryInterface;
use App\Repositories\Contracts\AdmissionRoundRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Support\EnrolmentStart;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The admin side of online admission: listing and reviewing applications, moving them
 * through their statuses, and converting an approved one into a student. Public
 * submission and the status lookup are AdmissionService. See
 * docs/tasks/online-admission.md.
 *
 * Statuses follow AdmissionApplication::TRANSITIONS. Approving checks the class's seats
 * under a lock on the round row, so two approvals can't both take the last seat.
 * Converting goes through StudentService::create() (student, student login, guardian
 * login shared by mobile, enrolment) into the round's academic year, in one transaction
 * with the application's own update.
 */
class AdmissionApplicationService
{
    public function __construct(
        private AdmissionApplicationRepositoryInterface $applications,
        private AdmissionRoundRepositoryInterface $rounds,
        private AcademicYearRepositoryInterface $years,
        private SectionRepositoryInterface $sections,
        private StudentService $students,
    ) {}

    /**
     * @param  array{round_id?: mixed, class_id?: mixed, status?: string, search?: string, search_sensitive?: bool}  $filters
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->applications->paginate($filters, $perPage);
    }

    /**
     * @param  array{round_id?: mixed, class_id?: mixed, search?: string, search_sensitive?: bool}  $filters
     * @return array<string, int>
     */
    public function counts(array $filters): array
    {
        return $this->applications->countsByStatus($filters);
    }

    public function find(AdmissionApplication $application): AdmissionApplication
    {
        return $this->applications->loadDetail($application);
    }

    /**
     * Moves the application to $data['status'] and records the optional test details,
     * score and note. 422 for a move AdmissionApplication::TRANSITIONS does not allow;
     * 409 when approving would exceed the class's seats.
     *
     * @param  array{status: string, test_at?: ?string, test_venue?: ?string, test_score?: mixed, admin_note?: ?string}  $data
     */
    public function changeStatus(AdmissionApplication $application, array $data, User $by): AdmissionApplication
    {
        return DB::transaction(function () use ($application, $data, $by) {
            $application = $this->applications->lockForUpdate($application);
            $target = $data['status'];

            if (! in_array($target, AdmissionApplication::TRANSITIONS[$application->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'status' => ["An application that is {$application->status} cannot be moved to {$target}."],
                ]);
            }

            if ($target === AdmissionApplication::STATUS_TEST_SCHEDULED && blank($data['test_at'] ?? null) && $application->test_at === null) {
                throw ValidationException::withMessages(['test_at' => ['The test date and time is required to schedule a test.']]);
            }

            if ($target === AdmissionApplication::STATUS_APPROVED) {
                $this->ensureSeatIsFree($application);
            }

            $attributes = ['status' => $target];

            foreach (['test_venue', 'test_score', 'admin_note'] as $field) {
                if (array_key_exists($field, $data)) {
                    $attributes[$field] = $data[$field];
                }
            }

            if (filled($data['test_at'] ?? null)) {
                // Stored in UTC; an offset-less value is read as UTC like every API timestamp.
                $attributes['test_at'] = Carbon::parse($data['test_at'])->utc();
            }

            if (in_array($target, [AdmissionApplication::STATUS_APPROVED, AdmissionApplication::STATUS_WAITLISTED, AdmissionApplication::STATUS_REJECTED], true)) {
                $attributes['decided_by'] = $by->id;
                $attributes['decided_at'] = now();
            }

            $updated = $this->applications->update($application, $attributes);

            return $this->applications->loadDetail($updated);
        });
    }

    /**
     * Creates the student from an approved application: profile, photo (copied from the
     * private disk into the student's public photo), logins and an enrolment in the
     * round's academic year. Sets `student_id` and the `admitted` status. 409 unless the
     * application is approved.
     *
     * Errors from the enrolment and the guardian login keep StudentService's keys
     * (`enrolment.section_id`, `enrolment.group`, `guardian_password`, ...).
     *
     * @param  array{section_id: int, roll_number?: ?int, group?: ?string, optional_subject_id?: ?int, password: string, guardian_password?: ?string}  $data
     */
    public function convert(AdmissionApplication $application, array $data): AdmissionApplication
    {
        $student = null;

        try {
            return DB::transaction(function () use ($application, $data, &$student) {
                $application = $this->applications->lockForUpdate($application);

                abort_unless(
                    $application->status === AdmissionApplication::STATUS_APPROVED,
                    409,
                    'Only an approved application can be converted to a student.'
                );

                $section = $this->sections->findOrFail((int) $data['section_id']);

                if ((int) $section->class_id !== (int) $application->class_id) {
                    throw ValidationException::withMessages([
                        'section_id' => ['The section must belong to the class the student was admitted to.'],
                    ]);
                }

                $year = $this->years->findOrFail((int) $this->applications->loadDetail($application)->round->academic_year_id);

                $student = $this->students->create(
                    $this->studentPayload($application, $data, $year),
                    $this->privateFileAsUpload($application->photo_path),
                    $year,
                );

                $updated = $this->applications->update($application, [
                    'status' => AdmissionApplication::STATUS_ADMITTED,
                    'student_id' => $student->id,
                ]);

                return $this->applications->loadDetail($updated);
            });
        } catch (\Throwable $e) {
            // StudentService stored the student's public photo before the outer transaction
            // failed, so the row is rolled back but the file would be orphaned.
            if ($student?->photo) {
                Storage::disk('public')->delete($student->photo);
            }

            throw $e;
        }
    }

    /**
     * Where an application's file is stored, for an authenticated download. 404 when the
     * application has no such file, or it is gone from the disk.
     *
     * @return array{disk: string, path: string, name: string}
     */
    public function file(AdmissionApplication $application, string $kind): array
    {
        $path = $application->filePath($kind);

        abort_if($path === null || ! Storage::disk(AdmissionService::DISK)->exists($path), 404);

        return [
            'disk' => AdmissionService::DISK,
            'path' => $path,
            'name' => $application->application_no.'-'.$kind.'.'.pathinfo($path, PATHINFO_EXTENSION),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function studentPayload(AdmissionApplication $application, array $data, AcademicYear $year): array
    {
        $profile = $application->only([
            'name_en', 'name_bn', 'gender', 'religion', 'blood_group', 'birth_registration_number', 'nationality',
            'present_address', 'permanent_address', 'district',
            'father_name_en', 'father_name_bn', 'father_mobile', 'mother_name_en', 'mother_name_bn', 'mother_mobile',
            'guardian_relation', 'guardian_name', 'guardian_mobile',
        ]);

        return $profile + [
            'date_of_birth' => $application->date_of_birth->toDateString(),
            // Today in Dhaka, kept inside the round's year (a round for next year admits
            // from that year's first day).
            'admission_date' => EnrolmentStart::for($year->start_date, $year->end_date, Carbon::now('Asia/Dhaka')->toDateString()),
            'password' => $data['password'],
            'guardian_password' => $data['guardian_password'] ?? null,
            'enrolment' => [
                'section_id' => (int) $data['section_id'],
                'group' => $data['group'] ?? $application->group,
                'optional_subject_id' => $data['optional_subject_id'] ?? null,
                'roll_number' => $data['roll_number'] ?? null,
            ],
        ];
    }

    /**
     * The stored photo as an upload object, so StudentService stores its own copy in the
     * student's public photo folder. Marked as a test upload because it did not come from
     * an HTTP request.
     */
    private function privateFileAsUpload(string $path): ?UploadedFile
    {
        $disk = Storage::disk(AdmissionService::DISK);

        if (! $disk->exists($path)) {
            return null;
        }

        return new UploadedFile($disk->path($path), basename($path), $disk->mimeType($path) ?: null, null, true);
    }

    private function ensureSeatIsFree(AdmissionApplication $application): void
    {
        $round = $this->rounds->lockForUpdate($this->applications->loadDetail($application)->round);
        $seats = $this->rounds->seatsFor($round, (int) $application->class_id);

        if ($seats !== null && $this->applications->countSeatsTaken($round->id, (int) $application->class_id, $application->id) >= $seats) {
            abort(409, 'All seats for this class in the round are taken.');
        }
    }
}
