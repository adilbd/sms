<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Certificate;
use App\Models\FeeDue;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\AttendanceRepositoryInterface;
use App\Repositories\Contracts\CertificateRepositoryInterface;
use App\Repositories\Contracts\ClassTeacherRepositoryInterface;
use App\Repositories\Contracts\FeeDueRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Repositories\Contracts\StudentEnrolmentRepositoryInterface;
use App\Repositories\Contracts\StudentRepositoryInterface;
use App\Support\AcademicGroup;
use App\Support\Money;
use App\Support\SchoolHeader;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Issuing certificates (testimonial, transfer, study, character) and cancelling them.
 * Every printed field is snapshotted into `data` at issue time, so a reprint always shows
 * what was issued and the print view only reads `data`.
 *
 *  - The serial number is `{PREFIX}-{Asia/Dhaka year}-{0001}` from a locked per-(type, year)
 *    counter row inside the issue transaction (retried up to 3 times, like fee receipts); a
 *    refused issue rolls the number back and a cancelled certificate's number is never reused.
 *  - A transfer certificate needs an active student, at most one non-cancelled TC, and no
 *    outstanding fees unless `allow_outstanding` comes with a note. Issuing it sets the
 *    student to `left` (leaving date = issue date) through StudentService::changeStatus(),
 *    and the enrolment follows. Cancelling it does NOT reactivate the student.
 *  - A study certificate needs an active student with an active enrolment in the active year.
 *  - A teacher reads the register only within their TeacherScope sections.
 */
class CertificateService
{
    public const DEFAULT_CONDUCT = 'ভালো';

    private const TIMEZONE = 'Asia/Dhaka';

    public function __construct(
        private CertificateRepositoryInterface $certificates,
        private StudentRepositoryInterface $students,
        private StudentEnrolmentRepositoryInterface $enrolments,
        private AcademicYearRepositoryInterface $years,
        private ClassTeacherRepositoryInterface $classTeachers,
        private StaffRepositoryInterface $staff,
        private AttendanceRepositoryInterface $attendance,
        private FeeDueRepositoryInterface $dues,
        private InstituteSettingsService $institute,
        private StudentService $studentService,
        private EnrolmentService $enrolmentService,
        private TeacherScope $teacherScope,
    ) {}

    /** Today's date in the school's time zone (Y-m-d). */
    public static function today(): string
    {
        return CarbonImmutable::now(self::TIMEZONE)->toDateString();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, int $perPage, User $viewer): LengthAwarePaginator
    {
        $scope = $this->teacherScope->sectionIdsFor($viewer);

        if ($scope !== null) {
            $filters['scope_section_ids'] = $scope;
        }

        return $this->certificates->paginate($filters, $perPage);
    }

    public function find(Certificate $certificate, User $viewer): Certificate
    {
        $this->certificates->loadDetail($certificate);
        $this->ensureVisible($certificate, $viewer);

        return $certificate;
    }

    /**
     * @param  array<string, mixed>  $data  validated input: `type`, `student_id` and the fields of that type
     */
    public function issue(array $data, User $actor): Certificate
    {
        $type = $data['type'];
        $issuedOn = self::today();
        $student = $this->students->findOrFail((int) $data['student_id']);
        $activeYear = $this->years->findActive();
        $enrolment = $this->placement($type, $student, $activeYear);
        $year = $enrolment?->academicYear ?? $activeYear;

        if ($year === null) {
            throw ValidationException::withMessages(['student_id' => ['There is no academic year to issue this certificate for.']]);
        }

        // Two issues of one type can contend for the counter row's gap lock on MySQL, so the
        // transaction is retried up to 3 times; the closure only touches the database.
        $certificate = DB::transaction(function () use ($data, $type, $issuedOn, $actor, $enrolment, $year) {
            $student = $this->certificates->lockStudent((int) $data['student_id']);

            if ($type === Certificate::TYPE_TRANSFER) {
                // Before the status check: the first TC leaves the student `left`, and a
                // second one is a conflict, not a "not active" problem.
                abort_if($this->certificates->hasActiveTransfer($student->id), 409, 'The student already has a transfer certificate.');
            }

            if ($type === Certificate::TYPE_TRANSFER || $type === Certificate::TYPE_STUDY) {
                $this->ensureActive($student, $type);
            }

            $extra = match ($type) {
                Certificate::TYPE_TRANSFER => $this->transferFields($student, $data, $issuedOn),
                Certificate::TYPE_TESTIMONIAL => $this->testimonialFields($data),
                Certificate::TYPE_CHARACTER => $this->characterFields($data),
                default => [],
            };

            $number = $this->certificates->nextSerialNumber($type, (int) substr($issuedOn, 0, 4));

            $certificate = $this->certificates->create([
                'type' => $type,
                'serial_no' => sprintf('%s-%s-%04d', Certificate::PREFIXES[$type], substr($issuedOn, 0, 4), $number),
                'student_id' => $student->id,
                'enrolment_id' => $enrolment?->id,
                'academic_year_id' => $year->id,
                'issued_on' => $issuedOn,
                'issued_by' => $actor->id,
                'data' => $this->snapshot($student, $enrolment, $year, $extra),
            ]);

            if ($type === Certificate::TYPE_TRANSFER) {
                $left = $this->studentService->changeStatus($student, Student::STATUS_LEFT, $issuedOn);
                $this->enrolmentService->syncStatus($left, $enrolment->academicYear);
            }

            return $certificate;
        }, 3);

        return $this->certificates->loadDetail($certificate);
    }

    /**
     * Cancels a certificate (admin only; the route requires delete-students). The number
     * stays in the register. A cancelled transfer certificate leaves the student `left`.
     */
    public function cancel(Certificate $certificate, string $reason, User $admin): Certificate
    {
        $cancelled = DB::transaction(function () use ($certificate, $reason, $admin) {
            $locked = $this->certificates->lockCertificate($certificate);

            abort_if($locked->isCancelled(), 409, 'This certificate has already been cancelled.');

            return $this->certificates->update($locked, [
                'cancelled_at' => CarbonImmutable::now('UTC'),
                'cancelled_by' => $admin->id,
                'cancel_reason' => $reason,
            ]);
        });

        return $this->certificates->loadDetail($cancelled);
    }

    private function ensureVisible(Certificate $certificate, User $viewer): void
    {
        $scope = $this->teacherScope->sectionIdsFor($viewer);

        if ($scope === null) {
            return;
        }

        abort_unless(
            $certificate->enrolment !== null && in_array((int) $certificate->enrolment->section_id, $scope, true),
            403,
            'This certificate is not for one of your students.'
        );
    }

    /**
     * The enrolment a certificate is issued from: the active-year active enrolment for a
     * study certificate, the latest one otherwise (required for a testimonial and a TC).
     */
    private function placement(string $type, Student $student, ?AcademicYear $activeYear): ?StudentEnrolment
    {
        if ($type === Certificate::TYPE_STUDY) {
            if ($student->status !== Student::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['student_id' => ['A study certificate can only be issued to an active student.']]);
            }

            $enrolment = $activeYear ? $this->enrolments->forStudentAndYear($student, $activeYear->id) : null;

            if ($enrolment === null || $enrolment->status !== StudentEnrolment::STATUS_ACTIVE) {
                throw ValidationException::withMessages(['student_id' => ['The student has no active enrolment in the active academic year.']]);
            }

            return $enrolment->load(['academicYear', 'class', 'section.shift']);
        }

        $enrolment = $this->enrolments->latestFor($student);

        if ($enrolment === null && $type !== Certificate::TYPE_CHARACTER) {
            throw ValidationException::withMessages(['student_id' => ['The student has no enrolment to issue this certificate from.']]);
        }

        return $enrolment;
    }

    private function ensureActive(Student $student, string $type): void
    {
        if ($student->status !== Student::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'student_id' => [$type === Certificate::TYPE_TRANSFER
                    ? 'A transfer certificate can only be issued to an active student.'
                    : 'A study certificate can only be issued to an active student.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function transferFields(Student $student, array $data, string $issuedOn): array
    {
        // A leaving-date problem would otherwise surface from changeStatus() after the write.
        $errors = $this->studentService->leavingDateErrors((clone $student)->fill([
            'status' => Student::STATUS_LEFT,
            'leaving_date' => $issuedOn,
        ]));

        if ($errors) {
            throw ValidationException::withMessages(['student_id' => array_merge(...array_values($errors))]);
        }

        $outstanding = (int) $this->dues->openForStudent($student->id)
            ->sum(fn (FeeDue $due) => $due->outstandingPaisa());
        $override = $outstanding > 0 && filter_var($data['allow_outstanding'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($outstanding > 0 && ! $override) {
            abort(409, 'The student has outstanding fees of '.Money::display(Money::fromPaisa($outstanding), false)
                .'. Clear them, or issue with an override and a note.');
        }

        return [
            'admission_date' => $student->admission_date?->toDateString(),
            'last_attendance_date' => $this->attendance->lastDateForStudent($student->id),
            'reason' => $data['reason'],
            'conduct' => $this->conduct($data),
            'dues' => [
                'status' => $override ? 'overridden' : 'clear',
                'outstanding' => Money::fromPaisa($outstanding),
                'note' => $override ? ($data['outstanding_note'] ?? null) : null,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function testimonialFields(array $data): array
    {
        $gpa = $data['gpa'] ?? null;

        return [
            'exam' => $data['exam'] ?? null,
            'exam_roll' => $data['exam_roll'] ?? null,
            'registration_no' => $data['registration_no'] ?? null,
            'board' => $data['board'] ?? null,
            'passing_year' => isset($data['passing_year']) ? (int) $data['passing_year'] : null,
            'gpa' => $gpa === null || $gpa === '' ? null : Money::fromPaisa(Money::toPaisa($gpa)),
            'session' => $data['session'] ?? null,
            'conduct' => $this->conduct($data),
            'remarks' => $data['remarks'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function characterFields(array $data): array
    {
        return ['conduct' => $this->conduct($data), 'remarks' => $data['remarks'] ?? null];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function conduct(array $data): string
    {
        return filled($data['conduct'] ?? null) ? $data['conduct'] : self::DEFAULT_CONDUCT;
    }

    /**
     * Every printed field, as it is at issue time.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function snapshot(Student $student, ?StudentEnrolment $enrolment, AcademicYear $year, array $extra): array
    {
        $section = $enrolment?->section;
        $class = $enrolment?->class;
        $profile = $this->institute->profile();

        $classTeacher = null;
        $head = null;

        if ($section !== null) {
            $main = $this->classTeachers->forSectionAndYear($section, $year->id)->firstWhere('is_main', true)?->staff;
            $classTeacher = $main ? ['name_en' => $main->name_en, 'name_bn' => $main->name_bn] : null;

            $headStaff = $this->staff->activeHeadForShift((int) $section->shift_id);
            $head = $headStaff ? [
                'name_en' => $headStaff->name_en,
                'name_bn' => $headStaff->name_bn,
                'designation' => $headStaff->designation,
            ] : null;
        }

        $group = $enrolment?->group;

        return [
            'school' => SchoolHeader::from($profile),
            'student' => [
                'student_id' => $student->student_id,
                'name_en' => $student->name_en,
                'name_bn' => $student->name_bn,
                'father_name_en' => $student->father_name_en,
                'father_name_bn' => $student->father_name_bn,
                'mother_name_en' => $student->mother_name_en,
                'mother_name_bn' => $student->mother_name_bn,
                'date_of_birth' => $student->date_of_birth?->toDateString(),
            ],
            'placement' => [
                'class_name_en' => $class?->name,
                'class_name_bn' => $class?->name_bn,
                'class_number' => $class?->number,
                'section' => $section?->name,
                'shift_name_en' => $section?->shift?->name_en,
                'shift_name_bn' => $section?->shift?->name_bn,
                'group' => $group,
                'group_en' => $group ? (AcademicGroup::LABELS_EN[$group] ?? $group) : null,
                'group_bn' => $group ? (AcademicGroup::LABELS_BN[$group] ?? $group) : null,
                'roll_number' => $enrolment?->roll_number,
                'academic_year' => $year->name ?: (string) $year->year,
            ],
            'class_teacher' => $classTeacher,
            'head_teacher' => $head,
            ...$extra,
        ];
    }
}
