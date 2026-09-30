<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Repositories\Contracts\ClassSubjectRepositoryInterface;
use App\Repositories\Contracts\StudentEnrolmentRepositoryInterface;
use App\Services\EnrolmentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Services are unit-tested against mocked repository interfaces — no database rows.
 */
class EnrolmentServiceTest extends TestCase
{
    private AcademicYear $year;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->year = new AcademicYear;
        $this->year->id = 1;
        $this->student = new Student(['status' => Student::STATUS_ACTIVE]);
        $this->student->id = 5;
    }

    private function section(int $classNumber = 5, array $attributes = [], bool $shiftActive = true): Section
    {
        $class = new Classes(['number' => $classNumber]);
        $class->id = 30 + $classNumber;

        $shift = new Shift;
        $shift->is_active = $shiftActive;

        $section = new Section($attributes);
        $section->id = 9;
        $section->class_id = $class->id;
        $section->setRelation('class', $class);
        $section->setRelation('shift', $shift);

        return $section;
    }

    private function mockRepo(Section $section, ?StudentEnrolment $existing = null, int $seatsTaken = 0, bool $rollTaken = false): void
    {
        $this->mock(StudentEnrolmentRepositoryInterface::class, function (MockInterface $mock) use ($section, $existing, $seatsTaken, $rollTaken) {
            $mock->shouldReceive('lockSection')->once()->with($section->id)->andReturn($section);
            $mock->shouldReceive('forStudentAndYear')->once()->andReturn($existing);
            $mock->shouldReceive('countActiveInSection')->andReturn($seatsTaken);
            $mock->shouldReceive('rollNumberTaken')->andReturn($rollTaken);
        });
    }

    private function assertInvalid(array $data, string $key, ?Section $section = null, ?StudentEnrolment $existing = null, int $seats = 0, bool $rollTaken = false): void
    {
        $section ??= $this->section();
        $this->mockRepo($section, $existing, $seats, $rollTaken);
        $this->mock(ClassSubjectRepositoryInterface::class);

        try {
            app(EnrolmentService::class)->save($this->student, $this->year, ['section_id' => $section->id] + $data);
            $this->fail("Expected a validation error for {$key}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    public function test_creates_an_enrolment_with_the_class_taken_from_the_section(): void
    {
        $section = $this->section();
        $this->mock(ClassSubjectRepositoryInterface::class);

        $this->mock(StudentEnrolmentRepositoryInterface::class, function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('lockSection')->with($section->id)->andReturn($section);
            $mock->shouldReceive('forStudentAndYear')->andReturn(null);
            $mock->shouldReceive('countActiveInSection')->andReturn(0);
            $mock->shouldReceive('rollNumberTaken')->with($section->id, 1, 12, null)->andReturn(false);
            $mock->shouldReceive('create')->once()->with([
                'class_id' => $section->class_id,
                'section_id' => $section->id,
                'group' => null,
                'optional_subject_id' => null,
                'roll_number' => 12,
                'status' => StudentEnrolment::STATUS_ACTIVE,
                'student_id' => 5,
                'academic_year_id' => 1,
            ])->andReturn(new StudentEnrolment);
        });

        app(EnrolmentService::class)->save($this->student, $this->year, ['section_id' => $section->id, 'roll_number' => 12]);
    }

    public function test_rejects_an_inactive_section(): void
    {
        $this->assertInvalid([], 'enrolment.section_id', $this->section(5, ['is_active' => false]));
    }

    public function test_rejects_an_inactive_shift(): void
    {
        $this->assertInvalid([], 'enrolment.section_id', $this->section(5, [], shiftActive: false));
    }

    public function test_rejects_a_full_section(): void
    {
        $this->assertInvalid([], 'enrolment.section_id', $this->section(5, ['capacity' => 2]), seats: 2);
    }

    public function test_a_student_keeping_their_section_skips_the_active_and_capacity_checks(): void
    {
        $section = $this->section(5, ['capacity' => 1, 'is_active' => false]);
        $existing = new StudentEnrolment(['section_id' => $section->id]);
        $existing->id = 77;

        $this->mock(StudentEnrolmentRepositoryInterface::class, function (MockInterface $mock) use ($section, $existing) {
            $mock->shouldReceive('lockSection')->andReturn($section);
            $mock->shouldReceive('forStudentAndYear')->andReturn($existing);
            $mock->shouldNotReceive('countActiveInSection');
            $mock->shouldReceive('update')->once()->with($existing, \Mockery::type('array'))->andReturn($existing);
        });
        $this->mock(ClassSubjectRepositoryInterface::class);

        app(EnrolmentService::class)->save($this->student, $this->year, ['section_id' => $section->id]);
    }

    public function test_requires_a_group_from_class_9(): void
    {
        $this->assertInvalid([], 'enrolment.group', $this->section(9));
    }

    public function test_forbids_a_group_below_class_9(): void
    {
        $this->assertInvalid(['group' => 'science'], 'enrolment.group', $this->section(8));
    }

    public function test_rejects_an_unknown_group(): void
    {
        $this->assertInvalid(['group' => 'arts'], 'enrolment.group', $this->section(9));
    }

    public function test_the_group_must_match_the_sections_group(): void
    {
        $this->assertInvalid(['group' => 'science'], 'enrolment.group', $this->section(10, ['group' => 'humanities']));
    }

    public function test_forbids_a_fourth_subject_below_class_9(): void
    {
        $this->assertInvalid(['optional_subject_id' => 3], 'enrolment.optional_subject_id', $this->section(7));
    }

    public function test_the_fourth_subject_must_be_an_optional_curriculum_row_for_the_class_and_group(): void
    {
        $section = $this->section(9);
        $this->mockRepo($section);
        $this->mock(ClassSubjectRepositoryInterface::class, function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('forClassAndGroup')->once()->with($section->class, 'science')->andReturn(new Collection([
                new ClassSubject(['subject_id' => 3, 'type' => ClassSubject::TYPE_COMPULSORY]),
                new ClassSubject(['subject_id' => 4, 'type' => ClassSubject::TYPE_OPTIONAL]),
            ]));
        });

        try {
            app(EnrolmentService::class)->save($this->student, $this->year, ['section_id' => 9, 'group' => 'science', 'optional_subject_id' => 3]);
            $this->fail('A compulsory subject is not a 4th-subject choice.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('enrolment.optional_subject_id', $e->errors());
        }
    }

    public function test_accepts_an_optional_curriculum_subject_as_the_fourth_subject(): void
    {
        $section = $this->section(9);
        $this->mock(StudentEnrolmentRepositoryInterface::class, function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('lockSection')->andReturn($section);
            $mock->shouldReceive('forStudentAndYear')->andReturn(null);
            $mock->shouldReceive('countActiveInSection')->andReturn(0);
            $mock->shouldReceive('create')->once()->with(\Mockery::on(
                fn (array $a) => $a['group'] === 'science' && $a['optional_subject_id'] === 4
            ))->andReturn(new StudentEnrolment);
        });
        $this->mock(ClassSubjectRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('forClassAndGroup')->once()->andReturn(new Collection([
                new ClassSubject(['subject_id' => 4, 'type' => ClassSubject::TYPE_OPTIONAL]),
            ]));
        });

        app(EnrolmentService::class)->save($this->student, $this->year, ['section_id' => 9, 'group' => 'science', 'optional_subject_id' => 4]);
    }

    public function test_rejects_a_roll_number_already_taken_in_the_section_and_year(): void
    {
        $this->assertInvalid(['roll_number' => 4], 'enrolment.roll_number', rollTaken: true);
    }

    public function test_reports_all_problems_at_once(): void
    {
        $section = $this->section(5, ['is_active' => false]);
        $this->mockRepo($section, rollTaken: true);
        $this->mock(ClassSubjectRepositoryInterface::class);

        try {
            app(EnrolmentService::class)->save($this->student, $this->year, ['section_id' => 9, 'group' => 'science', 'roll_number' => 1]);
            $this->fail('Expected validation errors.');
        } catch (ValidationException $e) {
            $this->assertEqualsCanonicalizing(['enrolment.section_id', 'enrolment.group', 'enrolment.roll_number'], array_keys($e->errors()));
        }
    }

    public function test_a_concurrent_roll_number_clash_becomes_a_validation_error(): void
    {
        $section = $this->section();
        $this->mock(StudentEnrolmentRepositoryInterface::class, function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('lockSection')->andReturn($section);
            $mock->shouldReceive('forStudentAndYear')->andReturn(null);
            $mock->shouldReceive('countActiveInSection')->andReturn(0);
            $mock->shouldReceive('rollNumberTaken')->andReturn(false);
            $mock->shouldReceive('create')->andThrow(new UniqueConstraintViolationException(
                'sqlite', 'insert', [], new \Exception('UNIQUE constraint failed: student_enrolments.section_id, student_enrolments.academic_year_id, student_enrolments.roll_number')
            ));
        });
        $this->mock(ClassSubjectRepositoryInterface::class);

        try {
            app(EnrolmentService::class)->save($this->student, $this->year, ['section_id' => 9, 'roll_number' => 2]);
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('enrolment.roll_number', $e->errors());
        }
    }

    public function test_another_unique_violation_is_not_reported_as_a_roll_number_error(): void
    {
        $section = $this->section();
        $this->mock(StudentEnrolmentRepositoryInterface::class, function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('lockSection')->andReturn($section);
            $mock->shouldReceive('forStudentAndYear')->andReturn(null);
            $mock->shouldReceive('countActiveInSection')->andReturn(0);
            $mock->shouldReceive('create')->andThrow(new UniqueConstraintViolationException(
                'sqlite', 'insert', [], new \Exception('UNIQUE constraint failed: student_enrolments.student_id, student_enrolments.academic_year_id')
            ));
        });
        $this->mock(ClassSubjectRepositoryInterface::class);

        $this->expectException(UniqueConstraintViolationException::class);

        app(EnrolmentService::class)->save($this->student, $this->year, ['section_id' => 9]);
    }

    public function test_the_enrolment_status_follows_the_students_status(): void
    {
        $section = $this->section();
        $this->student->status = Student::STATUS_GRADUATED;

        $this->mock(StudentEnrolmentRepositoryInterface::class, function (MockInterface $mock) use ($section) {
            $mock->shouldReceive('lockSection')->andReturn($section);
            $mock->shouldReceive('forStudentAndYear')->andReturn(null);
            $mock->shouldReceive('countActiveInSection')->andReturn(0);
            $mock->shouldReceive('create')->once()->with(\Mockery::on(fn (array $a) => $a['status'] === StudentEnrolment::STATUS_GRADUATED))->andReturn(new StudentEnrolment);
        });
        $this->mock(ClassSubjectRepositoryInterface::class);

        app(EnrolmentService::class)->save($this->student, $this->year, ['section_id' => 9]);
    }

    public function test_sync_status_updates_an_active_enrolment_of_a_student_who_left(): void
    {
        $existing = new StudentEnrolment(['status' => StudentEnrolment::STATUS_ACTIVE]);
        $this->student->status = Student::STATUS_LEFT;

        $this->mock(StudentEnrolmentRepositoryInterface::class, function (MockInterface $mock) use ($existing) {
            $mock->shouldReceive('forStudentAndYear')->once()->andReturn($existing);
            $mock->shouldReceive('update')->once()->with($existing, ['status' => StudentEnrolment::STATUS_LEFT]);
        });
        $this->mock(ClassSubjectRepositoryInterface::class);

        app(EnrolmentService::class)->syncStatus($this->student, $this->year);
    }

    public function test_sync_status_leaves_promotion_history_alone(): void
    {
        $existing = new StudentEnrolment(['status' => StudentEnrolment::STATUS_PROMOTED]);
        $this->student->status = Student::STATUS_LEFT;

        $this->mock(StudentEnrolmentRepositoryInterface::class, function (MockInterface $mock) use ($existing) {
            $mock->shouldReceive('forStudentAndYear')->once()->andReturn($existing);
            $mock->shouldNotReceive('update');
        });
        $this->mock(ClassSubjectRepositoryInterface::class);

        app(EnrolmentService::class)->syncStatus($this->student, $this->year);
    }
}
