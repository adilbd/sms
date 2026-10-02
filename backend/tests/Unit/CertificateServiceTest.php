<?php

namespace Tests\Unit;

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
use App\Services\CertificateService;
use App\Services\EnrolmentService;
use App\Services\InstituteSettingsService;
use App\Services\StudentService;
use App\Services\TeacherScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The certificate rules against mocked repository interfaces, no database.
 */
class CertificateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 06:00:00');
    }

    private function student(string $status = Student::STATUS_ACTIVE): Student
    {
        $student = new Student(['status' => $status, 'student_id' => '20260001', 'name_en' => 'Rahim', 'admission_date' => '2026-01-05']);
        $student->id = 7;

        return $student;
    }

    private function year(): AcademicYear
    {
        $year = new AcademicYear(['year' => 2026, 'name' => '2026']);
        $year->id = 1;

        return $year;
    }

    private function enrolment(): StudentEnrolment
    {
        $enrolment = new StudentEnrolment(['status' => StudentEnrolment::STATUS_ACTIVE]);
        $enrolment->id = 3;
        $enrolment->setRelation('academicYear', $this->year());

        return $enrolment;
    }

    /**
     * Mocks every collaborator; $configure receives them by name.
     *
     * @param  callable(array<string, MockInterface>): void  $configure
     */
    private function service(callable $configure): CertificateService
    {
        $mocks = [];

        foreach ([
            'certificates' => CertificateRepositoryInterface::class,
            'enrolments' => StudentEnrolmentRepositoryInterface::class,
            'years' => AcademicYearRepositoryInterface::class,
            'classTeachers' => ClassTeacherRepositoryInterface::class,
            'staff' => StaffRepositoryInterface::class,
            'attendance' => AttendanceRepositoryInterface::class,
            'dues' => FeeDueRepositoryInterface::class,
            'institute' => InstituteSettingsService::class,
            'studentService' => StudentService::class,
            'enrolmentService' => EnrolmentService::class,
            'scope' => TeacherScope::class,
        ] as $name => $abstract) {
            $mocks[$name] = $this->mock($abstract);
        }

        $configure($mocks);

        return app(CertificateService::class);
    }

    private function due(string $amount): FeeDue
    {
        return new FeeDue(['net_amount' => $amount, 'paid_amount' => '0.00']);
    }

    public function test_a_character_certificate_gets_a_serial_from_the_counter_and_a_snapshot(): void
    {
        $service = $this->service(function (array $m) {
            $student = $this->student();
            $m['years']->shouldReceive('findActive')->andReturn($this->year());
            $m['enrolments']->shouldReceive('latestFor')->andReturn(null);
            $m['institute']->shouldReceive('profile')->andReturn(['name_en' => 'School']);
            $m['certificates']->shouldReceive('lockStudent')->with(7)->andReturn($student);
            $m['certificates']->shouldReceive('nextSerialNumber')->once()->with('character', 2026)->andReturn(12);
            $m['certificates']->shouldReceive('create')->once()->withArgs(fn (array $a) => $a['serial_no'] === 'CHR-2026-0012'
                && $a['issued_on'] === '2026-10-15'
                && $a['data']['conduct'] === 'ভালো'
                && $a['data']['school']['name_en'] === 'School'
                && $a['data']['class_teacher'] === null)->andReturn(new Certificate);
            $m['certificates']->shouldReceive('loadDetail')->andReturnArg(0);
        });

        $service->issue(['type' => 'character', 'student_id' => 7], new User);
    }

    public function test_a_transfer_with_outstanding_fees_is_a_conflict_that_uses_no_number(): void
    {
        $service = $this->service(function (array $m) {
            $student = $this->student();
            $m['years']->shouldReceive('findActive')->andReturn($this->year());
            $m['enrolments']->shouldReceive('latestFor')->andReturn($this->enrolment());
            $m['certificates']->shouldReceive('lockStudent')->andReturn($student);
            $m['certificates']->shouldReceive('hasActiveTransfer')->andReturn(false);
            $m['studentService']->shouldReceive('leavingDateErrors')->andReturn([]);
            $m['dues']->shouldReceive('openDueByMonth')->andReturn(new Collection([$this->due('500.00'), $this->due('300.50')]));
            $m['certificates']->shouldNotReceive('nextSerialNumber');
            $m['certificates']->shouldNotReceive('create');
            $m['studentService']->shouldNotReceive('changeStatus');
        });

        try {
            $service->issue(['type' => 'transfer', 'student_id' => 7, 'reason' => 'Moving'], new User);
            $this->fail('Expected a 409.');
        } catch (HttpResponseException $e) {
            $this->assertSame(409, $e->getResponse()->getStatusCode());
            $body = json_decode($e->getResponse()->getContent(), true);
            $this->assertSame('800.50', $body['outstanding']);
            $this->assertStringContainsString('800.50', $body['message']);
        }
    }

    public function test_a_transfer_with_an_override_marks_the_student_left(): void
    {
        $service = $this->service(function (array $m) {
            $student = $this->student();
            $enrolment = $this->enrolment();
            $m['years']->shouldReceive('findActive')->andReturn($this->year());
            $m['enrolments']->shouldReceive('latestFor')->andReturn($enrolment);
            $m['institute']->shouldReceive('profile')->andReturn([]);
            $m['certificates']->shouldReceive('lockStudent')->andReturn($student);
            $m['certificates']->shouldReceive('hasActiveTransfer')->andReturn(false);
            $m['studentService']->shouldReceive('leavingDateErrors')->andReturn([]);
            $m['dues']->shouldReceive('openDueByMonth')->andReturn(new Collection([$this->due('500.00')]));
            $m['attendance']->shouldReceive('lastDateForStudent')->with(7)->andReturn('2026-10-12');
            $m['certificates']->shouldReceive('nextSerialNumber')->once()->with('transfer', 2026)->andReturn(1);
            $m['certificates']->shouldReceive('create')->once()->withArgs(fn (array $a) => $a['serial_no'] === 'TC-2026-0001'
                && $a['data']['dues'] === ['status' => 'overridden', 'outstanding' => '500.00', 'note' => 'Approved']
                && $a['data']['last_attendance_date'] === '2026-10-12')->andReturn(new Certificate);
            $m['studentService']->shouldReceive('changeStatus')->once()->with($student, Student::STATUS_LEFT, '2026-10-15')->andReturn($student);
            $m['enrolmentService']->shouldReceive('syncStatus')->once();
            $m['certificates']->shouldReceive('loadDetail')->andReturnArg(0);
        });

        $service->issue(['type' => 'transfer', 'student_id' => 7, 'reason' => 'Moving', 'allow_outstanding' => true, 'outstanding_note' => 'Approved'], new User);
    }

    public function test_a_second_transfer_is_a_conflict_before_the_status_check(): void
    {
        $service = $this->service(function (array $m) {
            $left = $this->student(Student::STATUS_LEFT);
            $m['years']->shouldReceive('findActive')->andReturn($this->year());
            $m['enrolments']->shouldReceive('latestFor')->andReturn($this->enrolment());
            $m['certificates']->shouldReceive('lockStudent')->andReturn($left);
            $m['certificates']->shouldReceive('hasActiveTransfer')->andReturn(true);
            $m['certificates']->shouldNotReceive('create');
        });

        try {
            $service->issue(['type' => 'transfer', 'student_id' => 7, 'reason' => 'x'], new User);
            $this->fail('Expected a 409.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_a_transfer_to_a_student_who_has_left_is_a_validation_error(): void
    {
        $service = $this->service(function (array $m) {
            $left = $this->student(Student::STATUS_LEFT);
            $m['years']->shouldReceive('findActive')->andReturn($this->year());
            $m['enrolments']->shouldReceive('latestFor')->andReturn($this->enrolment());
            $m['certificates']->shouldReceive('lockStudent')->andReturn($left);
            $m['certificates']->shouldReceive('hasActiveTransfer')->andReturn(false);
            $m['certificates']->shouldNotReceive('create');
        });

        $this->expectException(ValidationException::class);

        $service->issue(['type' => 'transfer', 'student_id' => 7, 'reason' => 'x'], new User);
    }

    public function test_a_study_certificate_needs_an_active_enrolment_in_the_active_year(): void
    {
        $service = $this->service(function (array $m) {
            $m['years']->shouldReceive('findActive')->andReturn($this->year());
            $m['certificates']->shouldReceive('lockStudent')->andReturn($this->student());
            $m['enrolments']->shouldReceive('forStudentAndYear')->andReturn(null);
            $m['certificates']->shouldNotReceive('create');
        });

        try {
            $service->issue(['type' => 'study', 'student_id' => 7], new User);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('student_id', $e->errors());
        }
    }

    public function test_a_testimonial_needs_an_enrolment(): void
    {
        $service = $this->service(function (array $m) {
            $m['years']->shouldReceive('findActive')->andReturn($this->year());
            $m['certificates']->shouldReceive('lockStudent')->andReturn($this->student());
            $m['enrolments']->shouldReceive('latestFor')->andReturn(null);
            $m['certificates']->shouldNotReceive('create');
        });

        $this->expectException(ValidationException::class);

        $service->issue(['type' => 'testimonial', 'student_id' => 7], new User);
    }

    public function test_cancelling_twice_is_a_conflict(): void
    {
        $certificate = new Certificate(['cancelled_at' => now()]);
        $certificate->id = 5;

        $service = $this->service(function (array $m) use ($certificate) {
            $m['certificates']->shouldReceive('lockCertificate')->andReturn($certificate);
            $m['certificates']->shouldNotReceive('update');
        });

        try {
            $service->cancel($certificate, 'again', new User);
            $this->fail('Expected a 409.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_the_register_is_limited_to_a_teachers_sections(): void
    {
        $user = new User;

        $service = $this->service(function (array $m) use ($user) {
            $m['years']->shouldReceive('findActive')->andReturn($this->year());
            $m['scope']->shouldReceive('sectionIdsFor')->with($user, 1)->andReturn([4, 5]);
            $m['certificates']->shouldReceive('paginate')->once()
                ->withArgs(fn (array $filters, int $perPage) => $filters['scope_section_ids'] === [4, 5] && $filters['scope_academic_year_id'] === 1 && $perPage === 15)
                ->andReturn(new \Illuminate\Pagination\LengthAwarePaginator([], 0, 15));
        });

        $service->list([], 15, $user);
    }

    public function test_a_teacher_cannot_read_a_certificate_without_an_enrolment_in_their_sections(): void
    {
        $certificate = new Certificate;
        $enrolment = new StudentEnrolment(['section_id' => 9, 'academic_year_id' => 1]);
        $certificate->setRelation('enrolment', $enrolment);

        $service = $this->service(function (array $m) {
            $m['certificates']->shouldReceive('loadDetail')->andReturnArg(0);
            $m['years']->shouldReceive('findActive')->andReturn($this->year());
            $m['scope']->shouldReceive('sectionIdsFor')->andReturn([4]);
        });

        try {
            $service->find($certificate, new User);
            $this->fail('Expected a 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
