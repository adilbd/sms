<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\StudentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\EnrolmentService;
use App\Services\StudentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\FakeUniqueViolation;
use Tests\TestCase;

/**
 * Services are unit-tested against mocked repository interfaces — no database.
 */
class StudentServiceTest extends TestCase
{
    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->year = new AcademicYear;
        $this->year->id = 1;
    }

    private function user(int $id, bool $active = true): User
    {
        $user = new User(['is_active' => $active]);
        $user->id = $id;

        return $user;
    }

    private function data(array $override = []): array
    {
        return array_replace([
            'name_en' => 'Rahim Uddin',
            'date_of_birth' => '2014-04-10',
            'gender' => 'male',
            'admission_date' => '2026-01-05',
            'guardian_relation' => 'father',
            'guardian_name' => 'Karim Uddin',
            'guardian_mobile' => '01711111111',
            'guardian_password' => 'guardian-pass',
            'password' => 'student-pass',
            'enrolment' => ['section_id' => 9, 'roll_number' => 1],
        ], $override);
    }

    private function savedStudent(int $id = 50, array $attributes = []): Student
    {
        $student = new Student(array_replace([
            'student_id' => '20260001', 'name_en' => 'Rahim Uddin', 'status' => 'active',
            'admission_date' => '2026-01-05', 'guardian_mobile' => '01711111111', 'guardian_name' => 'Karim Uddin',
            'user_id' => 10, 'guardian_user_id' => 20,
        ], $attributes));
        $student->id = $id;

        return $student;
    }

    private function years(?AcademicYear $active = null): void
    {
        $active ??= $this->year;
        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldReceive('findActive')->andReturn($active));
    }

    private function noYear(): void
    {
        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldReceive('findActive')->andReturn(null));
    }

    public function test_create_makes_the_student_login_and_a_new_guardian_login(): void
    {
        $this->years();
        $created = $this->savedStudent();

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('findByUsername')->once()->with('01711111111')->andReturn(null);
            $mock->shouldReceive('createWithRole')->once()
                ->with(Mockery::on(fn ($a) => $a['username'] === '01711111111' && $a['password'] === 'guardian-pass' && $a['name'] === 'Karim Uddin'), 'parent')
                ->andReturn($this->user(20));
            $mock->shouldReceive('createWithRole')->once()
                ->with(Mockery::on(fn ($a) => $a['username'] === '20260001' && $a['password'] === 'student-pass' && $a['email'] === null && $a['is_active'] === true), 'student')
                ->andReturn($this->user(10));
        });
        $this->mock(StudentRepositoryInterface::class, function (MockInterface $mock) use ($created) {
            $mock->shouldReceive('nextStudentId')->once()->with(2026)->andReturn('20260001');
            $mock->shouldReceive('create')->once()
                ->with(Mockery::on(fn ($a) => $a['student_id'] === '20260001' && $a['user_id'] === 10 && $a['guardian_user_id'] === 20 && ! array_key_exists('password', $a) && ! array_key_exists('enrolment', $a)))
                ->andReturn($created);
            $mock->shouldReceive('hasActiveChildren')->andReturn(true);
            $mock->shouldReceive('loadDetail')->once()->with($created, 1)->andReturn($created);
        });
        $this->mock(EnrolmentService::class, function (MockInterface $mock) use ($created) {
            $mock->shouldReceive('save')->once()->with($created, $this->year, ['section_id' => 9, 'roll_number' => 1])->andReturn(new StudentEnrolment);
        });

        $this->assertSame($created, app(StudentService::class)->create($this->data(), null));
    }

    public function test_create_links_an_existing_guardian_without_a_password(): void
    {
        $this->years();
        $guardian = $this->user(20);
        $guardian->name = 'Karim Uddin';
        $created = $this->savedStudent();

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($guardian) {
            $mock->shouldReceive('findByUsername')->with('01711111111')->andReturn($guardian);
            $mock->shouldReceive('hasRole')->with($guardian, 'parent')->andReturn(true);
            $mock->shouldReceive('createWithRole')->once()->with(Mockery::any(), 'student')->andReturn($this->user(10));
            $mock->shouldNotReceive('update');
        });
        $this->mock(StudentRepositoryInterface::class, function (MockInterface $mock) use ($created) {
            $mock->shouldReceive('nextStudentId')->andReturn('20260002');
            $mock->shouldReceive('create')->with(Mockery::on(fn ($a) => $a['guardian_user_id'] === 20))->andReturn($created);
            $mock->shouldReceive('hasActiveChildren')->andReturn(true);
            $mock->shouldReceive('loadDetail')->andReturn($created);
        });
        $this->mock(EnrolmentService::class, fn (MockInterface $mock) => $mock->shouldReceive('save')->andReturn(new StudentEnrolment));

        app(StudentService::class)->create($this->data(['guardian_password' => null]), null);
    }

    public function test_create_needs_a_new_password_to_reuse_an_inactive_guardian_login(): void
    {
        $this->years();
        $guardian = $this->user(20, active: false);

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($guardian) {
            $mock->shouldReceive('findByUsername')->with('01711111111')->andReturn($guardian);
            $mock->shouldReceive('hasRole')->with($guardian, 'parent')->andReturn(true);
            $mock->shouldReceive('createWithRole')->andReturn($this->user(10));
            $mock->shouldNotReceive('update');
            $mock->shouldNotReceive('revokeAllTokens');
        });
        $this->mock(StudentRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldReceive('nextStudentId')->andReturn('20260002'));
        $this->mock(EnrolmentService::class);

        try {
            app(StudentService::class)->create($this->data(['guardian_password' => null]), null);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('guardian_password', $e->errors());
        }
    }

    public function test_create_sets_the_new_password_and_revokes_tokens_when_reusing_an_inactive_guardian_login(): void
    {
        $this->years();
        $guardian = $this->user(20, active: false);
        $created = $this->savedStudent();

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($guardian) {
            $mock->shouldReceive('findByUsername')->with('01711111111')->andReturn($guardian);
            $mock->shouldReceive('hasRole')->with($guardian, 'parent')->andReturn(true);
            $mock->shouldReceive('createWithRole')->andReturn($this->user(10));
            $mock->shouldReceive('update')->once()->with($guardian, ['name' => 'Karim Uddin', 'password' => 'guardian-pass'])->ordered();
            $mock->shouldReceive('revokeAllTokens')->once()->with($guardian)->ordered();
            $mock->shouldReceive('update')->once()->with($guardian, ['is_active' => true]);
        });
        $this->mock(StudentRepositoryInterface::class, function (MockInterface $mock) use ($created) {
            $mock->shouldReceive('nextStudentId')->andReturn('20260002');
            $mock->shouldReceive('create')->andReturn($created);
            $mock->shouldReceive('hasActiveChildren')->andReturn(true);
            $mock->shouldReceive('loadDetail')->andReturn($created);
        });
        $this->mock(EnrolmentService::class, fn (MockInterface $mock) => $mock->shouldReceive('save')->andReturn(new StudentEnrolment));

        app(StudentService::class)->create($this->data(), null);
    }

    public function test_create_needs_a_password_for_a_new_guardian(): void
    {
        $this->years();

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('findByUsername')->andReturn(null);
            $mock->shouldNotReceive('createWithRole');
        });
        $this->mock(StudentRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('nextStudentId')->andReturn('20260001');
            $mock->shouldNotReceive('create');
        });
        $this->mock(EnrolmentService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('save'));

        try {
            app(StudentService::class)->create($this->data(['guardian_password' => null]), null);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('guardian_password', $e->errors());
        }
    }

    public function test_create_rejects_a_guardian_mobile_owned_by_a_non_parent(): void
    {
        $this->years();
        $teacher = $this->user(3);

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($teacher) {
            $mock->shouldReceive('findByUsername')->andReturn($teacher);
            $mock->shouldReceive('hasRole')->with($teacher, 'parent')->andReturn(false);
            $mock->shouldNotReceive('createWithRole');
        });
        $this->mock(StudentRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldReceive('nextStudentId')->andReturn('20260001'));
        $this->mock(EnrolmentService::class);

        try {
            app(StudentService::class)->create($this->data(), null);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('guardian_mobile', $e->errors());
        }
    }

    public function test_create_requires_at_least_one_name(): void
    {
        $this->assertCreateFails($this->data(['name_en' => null, 'name_bn' => null]), 'name_en');
    }

    public function test_create_requires_a_leaving_date_for_a_non_active_status(): void
    {
        $this->assertCreateFails($this->data(['status' => 'left']), 'leaving_date');
    }

    public function test_create_rejects_a_leaving_date_before_admission(): void
    {
        $this->assertCreateFails($this->data(['status' => 'left', 'leaving_date' => '2025-12-31']), 'leaving_date');
    }

    public function test_create_needs_an_active_academic_year(): void
    {
        $this->noYear();
        $this->mock(StudentRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));
        $this->mock(UserRepositoryInterface::class);
        $this->mock(EnrolmentService::class);

        $this->expectException(ValidationException::class);

        app(StudentService::class)->create($this->data(), null);
    }

    private function assertCreateFails(array $data, string $key): void
    {
        $this->years();
        $this->mock(StudentRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));
        $this->mock(UserRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('createWithRole'));
        $this->mock(EnrolmentService::class);

        try {
            app(StudentService::class)->create($data, null);
            $this->fail("Expected a validation error for {$key}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    /**
     * @param  list<string>  $columns
     * @param  list<string>  $insertColumns
     */
    private function assertConcurrentClashBecomes(string $driver, string $where, string $table, array $columns, array $insertColumns, string $expectedKey): void
    {
        $exception = FakeUniqueViolation::make($driver, $table, $columns, $insertColumns);

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($where, $exception) {
            $mock->shouldReceive('findByUsername')->andReturn(null);
            if ($where === 'user') {
                $mock->shouldReceive('createWithRole')->andThrow($exception);
            } else {
                $mock->shouldReceive('createWithRole')->andReturn($this->user(20), $this->user(10));
            }
        });
        $this->mock(StudentRepositoryInterface::class, function (MockInterface $mock) use ($where, $exception) {
            $mock->shouldReceive('nextStudentId')->andReturn('20260001');
            if ($where === 'student') {
                $mock->shouldReceive('create')->andThrow($exception);
            }
        });
        $this->mock(EnrolmentService::class);

        try {
            app(StudentService::class)->create($this->data(), null);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame([$expectedKey], array_keys($e->errors()), "{$driver} {$table}.".implode(',', $columns));
        }
    }

    public function test_create_reports_a_concurrent_clash_on_the_right_field_for_each_driver(): void
    {
        $this->years();
        $userColumns = ['name', 'username', 'email', 'phone', 'password', 'is_active', 'updated_at', 'created_at'];
        $studentColumns = ['name_en', 'birth_registration_number', 'email', 'student_id', 'user_id', 'guardian_user_id'];

        foreach (FakeUniqueViolation::drivers() as $driver) {
            // The INSERT names `email` and `birth_registration_number` in every case, so a
            // substring match on the whole message would pick the wrong field.
            $this->assertConcurrentClashBecomes($driver, 'user', 'users', ['username'], $userColumns, 'student_id');
            $this->assertConcurrentClashBecomes($driver, 'user', 'users', ['email'], $userColumns, 'email');
            $this->assertConcurrentClashBecomes($driver, 'student', 'students', ['student_id'], $studentColumns, 'student_id');
            $this->assertConcurrentClashBecomes($driver, 'student', 'students', ['birth_registration_number'], $studentColumns, 'birth_registration_number');
        }
    }

    public function test_create_rethrows_an_unrecognised_unique_violation(): void
    {
        $this->years();
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('findByUsername')->andReturn(null);
            $mock->shouldReceive('createWithRole')->andThrow(FakeUniqueViolation::make('mysql', 'users', ['phone'], ['name', 'email', 'phone']));
        });
        $this->mock(StudentRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldReceive('nextStudentId')->andReturn('20260001'));
        $this->mock(EnrolmentService::class);

        $this->expectException(UniqueConstraintViolationException::class);

        app(StudentService::class)->create($this->data(), null);
    }

    public function test_update_relinks_the_guardian_and_deactivates_the_old_login_when_it_has_no_active_children(): void
    {
        $this->years();
        $student = $this->savedStudent();
        $oldGuardian = $this->user(20);
        $newGuardian = $this->user(21);

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($oldGuardian, $newGuardian) {
            $mock->shouldReceive('find')->with(10)->andReturn($this->user(10));
            $mock->shouldReceive('find')->with(20)->andReturn($oldGuardian);
            $mock->shouldReceive('findByUsername')->with('01822222222')->andReturn(null);
            $mock->shouldReceive('createWithRole')->once()->with(Mockery::on(fn ($a) => $a['username'] === '01822222222'), 'parent')->andReturn($newGuardian);
            $mock->shouldReceive('update')->with(Mockery::type(User::class), Mockery::type('array'));
            $mock->shouldReceive('revokeAllTokens');
        });
        $this->mock(StudentRepositoryInterface::class, function (MockInterface $mock) use ($oldGuardian, $newGuardian) {
            $mock->shouldReceive('update')->andReturnUsing(function (Student $student, array $attributes) {
                return $student->fill($attributes);
            });
            $mock->shouldReceive('hasActiveChildren')->with($newGuardian)->andReturn(true);
            $mock->shouldReceive('hasActiveChildren')->with($oldGuardian)->andReturn(false);
            $mock->shouldReceive('loadDetail')->andReturnUsing(fn (Student $s) => $s);
        });
        $this->mock(EnrolmentService::class, fn (MockInterface $mock) => $mock->shouldReceive('syncStatus'));

        $result = app(StudentService::class)->update($student, ['guardian_mobile' => '01822222222', 'guardian_password' => 'new-guardian-pass'], null, false);

        $this->assertSame(21, $result->guardian_user_id);
    }

    public function test_update_keeps_the_guardian_when_the_mobile_is_unchanged(): void
    {
        $this->years();
        $student = $this->savedStudent();

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('find')->with(10)->andReturn($this->user(10));
            $mock->shouldReceive('find')->with(20)->andReturn($this->user(20));
            $mock->shouldReceive('update')->with(Mockery::type(User::class), Mockery::type('array'));
            $mock->shouldReceive('revokeAllTokens');
            $mock->shouldNotReceive('findByUsername');
            $mock->shouldNotReceive('createWithRole');
        });
        $this->mock(StudentRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('update')->andReturnUsing(fn (Student $s, array $a) => $s->fill($a));
            $mock->shouldReceive('hasActiveChildren')->andReturn(true);
            $mock->shouldReceive('loadDetail')->andReturnUsing(fn (Student $s) => $s);
        });
        $this->mock(EnrolmentService::class, fn (MockInterface $mock) => $mock->shouldReceive('syncStatus'));

        app(StudentService::class)->update($student, ['district' => 'Dhaka'], null, false);
    }

    public function test_update_resets_the_student_password_only_when_given(): void
    {
        $this->years();
        $student = $this->savedStudent();
        $login = $this->user(10);

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($login) {
            $mock->shouldReceive('find')->with(10)->andReturn($login);
            $mock->shouldReceive('find')->with(20)->andReturn($this->user(20));
            $mock->shouldReceive('update')->once()->with($login, Mockery::on(fn ($a) => $a['password'] === 'brand-new-pass' && $a['is_active'] === true));
            $mock->shouldReceive('update')->with(Mockery::type(User::class), Mockery::type('array'));
            $mock->shouldReceive('revokeAllTokens')->with($login)->once();
        });
        $this->mock(StudentRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('update')->andReturnUsing(fn (Student $s, array $a) => $s->fill($a));
            $mock->shouldReceive('hasActiveChildren')->andReturn(true);
            $mock->shouldReceive('loadDetail')->andReturnUsing(fn (Student $s) => $s);
        });
        $this->mock(EnrolmentService::class, fn (MockInterface $mock) => $mock->shouldReceive('syncStatus'));

        app(StudentService::class)->update($student, ['password' => 'brand-new-pass'], null, false);
    }

    public function test_update_validates_the_leaving_date_against_saved_values(): void
    {
        $this->years();
        $this->mock(StudentRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('update'));
        $this->mock(UserRepositoryInterface::class);
        $this->mock(EnrolmentService::class);

        try {
            app(StudentService::class)->update($this->savedStudent(), ['status' => 'left'], null, false);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('leaving_date', $e->errors());
        }
    }

    public function test_update_saves_the_enrolment_when_sent(): void
    {
        $this->years();
        $student = $this->savedStudent();

        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('find')->andReturnUsing(fn (int $id) => $this->user($id));
            $mock->shouldReceive('update');
            $mock->shouldReceive('revokeAllTokens');
        });
        $this->mock(StudentRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('update')->andReturnUsing(fn (Student $s, array $a) => $s->fill($a));
            $mock->shouldReceive('hasActiveChildren')->andReturn(true);
            $mock->shouldReceive('loadDetail')->andReturnUsing(fn (Student $s) => $s);
        });
        $this->mock(EnrolmentService::class, function (MockInterface $mock) {
            $mock->shouldReceive('save')->once()->with(Mockery::type(Student::class), $this->year, ['section_id' => 4]);
            $mock->shouldNotReceive('syncStatus');
        });

        app(StudentService::class)->update($student, ['enrolment' => ['section_id' => 4]], null, false);
    }

    public function test_delete_is_refused_while_the_student_has_attendance_exam_results_or_fee_payments(): void
    {
        foreach (['hasAttendances', 'hasExamMarks', 'hasExamResults', 'hasFeePayments'] as $blocking) {
            $student = $this->savedStudent();

            $this->mock(StudentRepositoryInterface::class, function (MockInterface $mock) use ($student, $blocking) {
                foreach (['hasAttendances', 'hasExamMarks', 'hasExamResults', 'hasFeePayments'] as $method) {
                    $mock->shouldReceive($method)->with($student)->andReturn($method === $blocking);
                }
                $mock->shouldNotReceive('delete');
            });
            $this->mock(UserRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('update'));
            $this->mock(AcademicYearRepositoryInterface::class);
            $this->mock(EnrolmentService::class);

            try {
                app(StudentService::class)->delete($student);
                $this->fail("Expected a 409 for {$blocking}.");
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
        }
    }

    public function test_delete_deactivates_both_logins_when_the_guardian_has_no_other_active_child(): void
    {
        $student = $this->savedStudent();
        $login = $this->user(10);
        $guardian = $this->user(20);

        $this->mock(StudentRepositoryInterface::class, function (MockInterface $mock) use ($student, $guardian) {
            $mock->shouldReceive('hasAttendances', 'hasExamMarks', 'hasExamResults', 'hasFeePayments')->andReturn(false);
            $mock->shouldReceive('delete')->once()->with($student);
            $mock->shouldReceive('hasActiveChildren')->once()->with($guardian)->andReturn(false);
        });
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($login, $guardian) {
            $mock->shouldReceive('find')->with(10)->andReturn($login);
            $mock->shouldReceive('find')->with(20)->andReturn($guardian);
            $mock->shouldReceive('update')->once()->with($login, ['is_active' => false]);
            $mock->shouldReceive('update')->once()->with($guardian, ['is_active' => false]);
            $mock->shouldReceive('revokeAllTokens')->twice();
        });
        $this->years();
        $this->mock(EnrolmentService::class, fn (MockInterface $mock) => $mock->shouldReceive('release')->once()->with($student, $this->year));

        app(StudentService::class)->delete($student);
    }

    public function test_delete_keeps_the_guardian_login_while_a_sibling_is_active(): void
    {
        $student = $this->savedStudent();
        $login = $this->user(10);
        $guardian = $this->user(20);

        $this->mock(StudentRepositoryInterface::class, function (MockInterface $mock) use ($student) {
            $mock->shouldReceive('hasAttendances', 'hasExamMarks', 'hasExamResults', 'hasFeePayments')->andReturn(false);
            $mock->shouldReceive('delete')->once()->with($student);
            $mock->shouldReceive('hasActiveChildren')->andReturn(true);
        });
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($login, $guardian) {
            $mock->shouldReceive('find')->with(10)->andReturn($login);
            $mock->shouldReceive('find')->with(20)->andReturn($guardian);
            $mock->shouldReceive('update')->once()->with($login, ['is_active' => false]);
            $mock->shouldReceive('revokeAllTokens')->once()->with($login);
        });
        $this->years();
        $this->mock(EnrolmentService::class, fn (MockInterface $mock) => $mock->shouldReceive('release')->once()->with($student, $this->year));

        app(StudentService::class)->delete($student);
    }

    public function test_list_defaults_to_the_active_academic_year(): void
    {
        $this->years();
        $this->mock(StudentRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('paginate')->once()->with(['class_id' => 5, 'academic_year_id' => 1], 15);
        });
        $this->mock(UserRepositoryInterface::class);
        $this->mock(EnrolmentService::class);

        app(StudentService::class)->list(['class_id' => 5], 15);
    }

    public function test_list_keeps_an_explicit_academic_year(): void
    {
        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('findActive'));
        $this->mock(StudentRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('paginate')->once()->with(['academic_year_id' => 3], 10);
        });
        $this->mock(UserRepositoryInterface::class);
        $this->mock(EnrolmentService::class);

        app(StudentService::class)->list(['academic_year_id' => 3], 10);
    }

    public function test_find_own_aborts_with_404_when_no_student_is_linked(): void
    {
        $this->years();
        $this->mock(StudentRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldReceive('findByUser')->once()->with(Mockery::type(User::class), 1)->andReturn(null));
        $this->mock(UserRepositoryInterface::class);
        $this->mock(EnrolmentService::class);

        try {
            app(StudentService::class)->findOwn($this->user(3));
            $this->fail('Expected a 404.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }
}
