<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\ClassSection;
use App\Models\Holiday;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\AttendanceRepositoryInterface;
use App\Repositories\Contracts\HolidayRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Repositories\Contracts\StudentEnrolmentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\AttendanceService;
use App\Services\InstituteSettingsService;
use App\Services\StudentService;
use App\Services\TeacherContext;
use App\Services\TeacherScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Services are unit-tested against mocked repository interfaces, no database. Time is
 * frozen at 02:30 on Thursday 2026-10-08 in Asia/Dhaka (still 2026-10-07 in UTC).
 */
class AttendanceServiceTest extends TestCase
{
    private const TODAY = '2026-10-08';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-07 20:30:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function user(int $id = 5): User
    {
        $user = new User;
        $user->id = $id;

        return $user;
    }

    private function section(int $id = 20): Section
    {
        $section = new Section(['class_id' => 9]);
        $section->id = $id;

        return $section;
    }

    private function year(): AcademicYear
    {
        $year = new AcademicYear(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $year->id = 3;

        return $year;
    }

    private function enrolment(int $id, int $studentId, array $attributes = []): StudentEnrolment
    {
        $enrolment = new StudentEnrolment(['student_id' => $studentId, 'roll_number' => $id, 'status' => StudentEnrolment::STATUS_ACTIVE, ...$attributes]);
        $enrolment->id = $id;
        $enrolment->setRelation('attendances', new Collection);
        $enrolment->setRelation('student', tap(new Student(['student_id' => "S{$studentId}", 'name_en' => "Student {$studentId}"]), fn ($s) => $s->id = $studentId));

        return $enrolment;
    }

    private function attendance(string $date, string $status): Attendance
    {
        return new Attendance(['date' => $date, 'status' => $status]);
    }

    /**
     * @param  string  $role  'admin' or 'teacher'
     * @param  list<int>  $leads  section ids the teacher leads as class teacher
     */
    private function wire(string $role = 'teacher', array $leads = [20], ?callable $attendance = null, array $weekly = ['friday'], ?callable $holidays = null, string $staffStatus = Staff::STATUS_ACTIVE): void
    {
        $year = $this->year();
        $section = $this->section();

        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findByYear')->with(2026)->andReturn($year)->byDefault());
        $this->mock(SectionRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findOrFail')->andReturn($section)->byDefault());
        $this->mock(UserRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('hasRole')->andReturnUsing(fn ($u, $r) => $r === $role)->byDefault());
        $this->mock(InstituteSettingsService::class, fn (MockInterface $m) => $m->shouldReceive('weeklyHolidays')->andReturn($weekly)->byDefault());
        $this->mock(StudentEnrolmentRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('lockSection')->andReturn($section)->byDefault());
        $this->mock(HolidayRepositoryInterface::class, function (MockInterface $m) use ($holidays) {
            $m->shouldReceive('findByDate')->andReturn(null)->byDefault();
            $m->shouldReceive('between')->andReturn(new Collection)->byDefault();
            $holidays && $holidays($m);
        });
        $this->mock(AttendanceRepositoryInterface::class, function (MockInterface $m) use ($attendance) {
            $m->shouldReceive('candidateEnrolments')->andReturn(new Collection([$this->enrolment(100, 1), $this->enrolment(101, 2)]))->byDefault();
            $attendance && $attendance($m);
        });
        $this->mock(TeacherScope::class, function (MockInterface $m) use ($leads, $year, $staffStatus) {
            $staff = new Staff(['status' => $staffStatus]);
            $classSections = new Collection(array_map(fn ($id) => new ClassSection(['section_id' => $id]), $leads));
            $m->shouldReceive('forUser')->with(\Mockery::type(User::class), 3)
                ->andReturn(new TeacherContext($staff, $year, new Collection, $classSections))->byDefault();
        });
    }

    private function service(): AttendanceService
    {
        return app(AttendanceService::class);
    }

    private function data(array $entries, ?string $date = null): array
    {
        return array_filter(['section_id' => 20, 'date' => $date, 'entries' => $entries]);
    }

    /** @return array<string, list<string>> */
    private function errors(callable $call): array
    {
        try {
            $call();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            return $e->errors();
        }
    }

    private function assertForbidden(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected a 403 HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // --- save ----------------------------------------------------------------------------

    public function test_save_locks_the_section_then_upserts_the_rows_as_the_user_for_today_in_dhaka(): void
    {
        $this->wire('teacher', [20], function (MockInterface $m) {
            $m->shouldReceive('saveRows')->once()->with(
                [
                    ['student_id' => 1, 'enrolment_id' => 100, 'status' => 'present', 'remarks' => null],
                    ['student_id' => 2, 'enrolment_id' => 101, 'status' => 'absent', 'remarks' => 'Fever'],
                ],
                20, 3, self::TODAY, 5
            );
        });

        $this->mock(StudentEnrolmentRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('lockSection')->once()->with(20)->andReturn($this->section()));

        $sheet = $this->service()->save($this->user(), $this->data([
            ['student_id' => 1, 'status' => 'present', 'remarks' => ''],
            ['student_id' => 2, 'status' => 'absent', 'remarks' => 'Fever'],
        ]));

        $this->assertSame(self::TODAY, $sheet['date']);
        $this->assertNull($sheet['holiday']);
    }

    public function test_only_the_class_teacher_or_an_admin_may_save(): void
    {
        foreach ([[], [21]] as $leads) {
            $this->wire('teacher', $leads, fn (MockInterface $m) => $m->shouldNotReceive('saveRows'));
            $this->mock(StudentEnrolmentRepositoryInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('lockSection'));

            $this->assertForbidden(fn () => $this->service()->save($this->user(), $this->data([['student_id' => 1, 'status' => 'present']])));
            $this->assertForbidden(fn () => $this->service()->sheet($this->user(), 20, null));
        }

        // No staff record linked to the login.
        $this->wire('teacher', [20], fn (MockInterface $m) => $m->shouldNotReceive('saveRows'));
        $this->mock(TeacherScope::class, fn (MockInterface $m) => $m->shouldReceive('forUser')->andReturn(null));
        $this->assertForbidden(fn () => $this->service()->save($this->user(), $this->data([['student_id' => 1, 'status' => 'present']])));

        // A retired class teacher.
        $this->wire('teacher', [20], fn (MockInterface $m) => $m->shouldNotReceive('saveRows'), staffStatus: Staff::STATUS_RETIRED);
        $this->assertForbidden(fn () => $this->service()->save($this->user(), $this->data([['student_id' => 1, 'status' => 'present']])));

        // An admin needs no class-teacher row.
        $this->wire('admin', [], fn (MockInterface $m) => $m->shouldReceive('saveRows')->once());
        $this->mock(TeacherScope::class, fn (MockInterface $m) => $m->shouldNotReceive('forUser'));
        $this->service()->save($this->user(), $this->data([['student_id' => 1, 'status' => 'present']]));
    }

    public function test_a_future_date_is_refused(): void
    {
        $this->wire('admin', [], fn (MockInterface $m) => $m->shouldNotReceive('saveRows'));

        $errors = $this->errors(fn () => $this->service()->save($this->user(), $this->data([['student_id' => 1, 'status' => 'present']], '2026-10-09')));

        $this->assertArrayHasKey('date', $errors);
    }

    public function test_a_date_outside_the_academic_year_is_refused(): void
    {
        $this->wire('admin', [], fn (MockInterface $m) => $m->shouldNotReceive('saveRows'));
        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findByYear')->with(2025)->andReturn(null));

        $errors = $this->errors(fn () => $this->service()->save($this->user(), $this->data([['student_id' => 1, 'status' => 'present']], '2025-10-08')));

        $this->assertArrayHasKey('date', $errors);
    }

    public function test_a_date_before_the_years_own_start_is_refused(): void
    {
        $this->wire('admin', [], fn (MockInterface $m) => $m->shouldNotReceive('saveRows'));
        $year = $this->year();
        $year->start_date = '2026-02-01';
        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findByYear')->andReturn($year));

        $errors = $this->errors(fn () => $this->service()->save($this->user(), $this->data([['student_id' => 1, 'status' => 'present']], '2026-01-15')));

        $this->assertArrayHasKey('date', $errors);
    }

    public function test_a_listed_holiday_is_refused_naming_it(): void
    {
        $this->wire('admin', [], fn (MockInterface $m) => $m->shouldNotReceive('saveRows'), holidays: function (MockInterface $m) {
            $m->shouldReceive('findByDate')->with('2026-10-07')->andReturn(new Holiday(['name_en' => 'Mid-term break']));
        });

        $errors = $this->errors(fn () => $this->service()->save($this->user(), $this->data([['student_id' => 1, 'status' => 'present']], '2026-10-07')));

        $this->assertStringContainsString('Mid-term break', $errors['date'][0]);
    }

    public function test_a_weekly_holiday_is_refused_naming_the_day_and_follows_the_setting(): void
    {
        $this->wire('admin', [], fn (MockInterface $m) => $m->shouldNotReceive('saveRows'));
        $errors = $this->errors(fn () => $this->service()->save($this->user(), $this->data([['student_id' => 1, 'status' => 'present']], '2026-10-02')));
        $this->assertStringContainsString('Friday', $errors['date'][0]);

        // Saturday 2026-10-03 only counts once the setting lists it.
        $this->wire('admin', [], fn (MockInterface $m) => $m->shouldNotReceive('saveRows'), weekly: ['friday', 'saturday']);
        $errors = $this->errors(fn () => $this->service()->save($this->user(), $this->data([['student_id' => 1, 'status' => 'present']], '2026-10-03')));
        $this->assertStringContainsString('Saturday', $errors['date'][0]);

        $this->wire('admin', [], fn (MockInterface $m) => $m->shouldReceive('saveRows')->once());
        $this->service()->save($this->user(), $this->data([['student_id' => 1, 'status' => 'present']], '2026-10-03'));
    }

    public function test_a_teacher_is_limited_to_the_last_seven_days_but_an_admin_is_not(): void
    {
        // 2026-10-02 is the oldest day of the window (a Friday), so use the next school day
        // 2026-10-03; the 1st is one day too old.
        $this->wire('teacher', [20], fn (MockInterface $m) => $m->shouldReceive('saveRows')->once());
        $this->service()->save($this->user(), $this->data([['student_id' => 1, 'status' => 'present']], '2026-10-03'));

        $this->wire('teacher', [20], fn (MockInterface $m) => $m->shouldNotReceive('saveRows'));
        $errors = $this->errors(fn () => $this->service()->save($this->user(), $this->data([['student_id' => 1, 'status' => 'present']], '2026-10-01')));
        $this->assertStringContainsString('7 days', $errors['date'][0]);

        $this->wire('admin', [], fn (MockInterface $m) => $m->shouldReceive('saveRows')->once());
        $this->service()->save($this->user(), $this->data([['student_id' => 1, 'status' => 'present']], '2026-09-17'));
    }

    public function test_students_off_the_sheet_are_refused_per_row_and_nothing_is_saved(): void
    {
        $this->wire('teacher', [20], fn (MockInterface $m) => $m->shouldNotReceive('saveRows'));

        $errors = $this->errors(fn () => $this->service()->save($this->user(), $this->data([
            ['student_id' => 1, 'status' => 'present'],
            ['student_id' => 99, 'status' => 'present'],
            ['student_id' => 98, 'status' => 'late'],
        ])));

        $this->assertSame(['entries.1.student_id', 'entries.2.student_id'], array_keys($errors));
    }

    public function test_a_concurrent_save_of_the_same_day_becomes_a_validation_error(): void
    {
        $this->wire('teacher', [20], function (MockInterface $m) {
            $previous = new \PDOException('SQLSTATE[23000]: UNIQUE constraint failed: attendances.enrolment_id, attendances.date');
            $m->shouldReceive('saveRows')->andThrow(new UniqueConstraintViolationException('sqlite', 'insert', [], $previous));
        });

        $errors = $this->errors(fn () => $this->service()->save($this->user(), $this->data([['student_id' => 1, 'status' => 'present']])));

        $this->assertArrayHasKey('date', $errors);
    }

    // --- reports -------------------------------------------------------------------------

    public function test_the_report_counts_present_and_late_over_the_school_days_so_far(): void
    {
        $present = $this->enrolment(100, 1);
        $present->setRelation('attendances', new Collection([
            $this->attendance('2026-10-01', 'present'),
            $this->attendance('2026-10-03', 'late'),
            $this->attendance('2026-10-04', 'absent'),
            // A Friday row (weekly holiday) is ignored.
            $this->attendance('2026-10-02', 'present'),
        ]));

        $this->wire('teacher', [20], fn (MockInterface $m) => $m->shouldReceive('reportEnrolments')->once()->with(20, 3, '2026-10-01', '2026-10-31')->andReturn(new Collection([$present])));

        $report = $this->service()->report($this->user(), 20, '2026-10');

        // 1st, then Saturday 3rd to Thursday 8th: 7 days.
        $this->assertSame(['2026-10-01', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'], $report['school_days']);
        $row = $report['students'][0];
        $this->assertSame(['present' => 1, 'absent' => 1, 'late' => 1, 'leave' => 0], $row['totals']);
        $this->assertSame(['2026-10-01' => 'present', '2026-10-03' => 'late', '2026-10-04' => 'absent'], $row['days']);
        $this->assertSame('28.57', $row['percentage']);
    }

    public function test_a_holiday_added_afterwards_removes_the_day_from_school_days_and_totals(): void
    {
        $enrolment = $this->enrolment(100, 1);
        $enrolment->setRelation('attendances', new Collection([$this->attendance('2026-10-06', 'absent'), $this->attendance('2026-10-07', 'present')]));

        $this->wire('teacher', [20], fn (MockInterface $m) => $m->shouldReceive('reportEnrolments')->andReturn(new Collection([$enrolment])), holidays: function (MockInterface $m) {
            $m->shouldReceive('between')->andReturn(new Collection([new Holiday(['date' => '2026-10-06'])]));
        });

        $report = $this->service()->report($this->user(), 20, '2026-10');

        $this->assertNotContains('2026-10-06', $report['school_days']);
        $this->assertSame(['2026-10-07' => 'present'], $report['students'][0]['days']);
        $this->assertSame(0, $report['students'][0]['totals']['absent']);
    }

    public function test_a_student_who_left_is_measured_over_the_days_recorded(): void
    {
        $left = $this->enrolment(100, 1, ['status' => StudentEnrolment::STATUS_LEFT]);
        $left->setRelation('attendances', new Collection([$this->attendance('2026-10-01', 'present'), $this->attendance('2026-10-03', 'absent')]));

        $this->wire('teacher', [20], fn (MockInterface $m) => $m->shouldReceive('reportEnrolments')->andReturn(new Collection([$left])));

        $row = $this->service()->report($this->user(), 20, '2026-10')['students'][0];

        $this->assertSame(['2026-10-01', '2026-10-03'], array_keys($row['days']));
        $this->assertSame('50.00', $row['percentage']);
    }

    public function test_the_report_month_defaults_to_the_current_month_in_dhaka(): void
    {
        $this->wire('teacher', [20], fn (MockInterface $m) => $m->shouldReceive('reportEnrolments')->once()->with(20, 3, '2026-10-01', '2026-10-31')->andReturn(new Collection));

        $this->assertSame('2026-10', $this->service()->report($this->user(), 20, null)['month']);
    }

    public function test_a_month_with_no_academic_year_is_refused(): void
    {
        $this->wire('teacher');
        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findByYear')->andReturn(null) && $m->shouldReceive('findActive')->andReturn($this->year()));

        $this->assertArrayHasKey('month', $this->errors(fn () => $this->service()->report($this->user(), 20, '2027-01')));
    }

    // --- own records ---------------------------------------------------------------------

    public function test_own_and_child_months_go_through_the_student_service(): void
    {
        $student = tap(new Student(['student_id' => 'S1', 'name_en' => 'Rahim']), fn ($s) => $s->id = 1);
        $enrolment = $this->enrolment(100, 1);

        $this->wire('student');
        $this->mock(StudentService::class, function (MockInterface $m) use ($student) {
            $m->shouldReceive('findOwn')->once()->andReturn($student);
            $m->shouldReceive('findChildOf')->once()->with(\Mockery::type(User::class), 1)->andReturn($student);
        });
        $this->mock(StudentEnrolmentRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('forStudentAndYear')->andReturn($enrolment));
        $this->mock(AttendanceRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('recordsForStudent')->andReturn(new Collection([$this->attendance('2026-10-01', 'present')])));

        $own = $this->service()->ownMonth($this->user(), '2026-10');
        $child = $this->service()->childMonth($this->user(), 1, '2026-10');

        $this->assertSame($own, $child);
        $this->assertSame(['2026-10-01' => 'present'], $own['days']);
        $this->assertSame('14.29', $own['percentage']);
        // 1 January to 8 October is 281 days, 40 of them Fridays.
        $this->assertSame(241, $own['year_to_date']['school_days']);
    }

    public function test_a_guardian_asking_for_another_familys_child_is_refused_before_any_attendance_is_read(): void
    {
        $this->wire('parent');
        $this->mock(StudentService::class, fn (MockInterface $m) => $m->shouldReceive('findChildOf')->andThrow(new HttpException(403)));
        $this->mock(AttendanceRepositoryInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('recordsForStudent'));

        $this->assertForbidden(fn () => $this->service()->childMonth($this->user(), 77, '2026-10'));
    }

    public function test_a_student_without_an_enrolment_that_year_is_a_404(): void
    {
        $this->wire('student');
        $this->mock(StudentService::class, fn (MockInterface $m) => $m->shouldReceive('findOwn')->andReturn(tap(new Student, fn ($s) => $s->id = 1)));
        $this->mock(StudentEnrolmentRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('forStudentAndYear')->andReturn(null));

        try {
            $this->service()->ownMonth($this->user(), null);
            $this->fail('Expected a 404.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
