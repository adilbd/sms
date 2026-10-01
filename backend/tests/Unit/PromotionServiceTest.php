<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\PromotionRepositoryInterface;
use App\Repositories\Contracts\StudentEnrolmentRepositoryInterface;
use App\Services\EnrolmentService;
use App\Services\PromotionService;
use App\Services\StudentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * PromotionService against mocked repositories and collaborating services: the lock order,
 * the checks that run before any write, and what each action calls.
 */
class PromotionServiceTest extends TestCase
{
    private AcademicYear $from;

    private AcademicYear $to;

    /** @var array<int, Section> */
    private array $sections = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->from = new AcademicYear(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $this->from->id = 1;
        $this->to = new AcademicYear(['year' => 2027, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
        $this->to->id = 2;
    }

    private function section(int $id, int $classNumber, array $attributes = []): Section
    {
        $class = new Classes(['number' => $classNumber]);
        $class->id = 100 + $classNumber;
        $shift = new Shift(['is_active' => true]);

        $section = new Section($attributes + ['capacity' => 40, 'is_active' => true]);
        $section->id = $id;
        $section->class_id = $class->id;
        $section->setRelation('class', $class);
        $section->setRelation('shift', $shift);

        return $this->sections[$id] = $section;
    }

    private function enrolment(int $id, array $attributes = []): StudentEnrolment
    {
        $student = new Student(['name_en' => "Student {$id}", 'status' => Student::STATUS_ACTIVE]);
        $student->id = $id;

        $enrolment = new StudentEnrolment($attributes + ['status' => StudentEnrolment::STATUS_ACTIVE]);
        $enrolment->id = 1000 + $id;
        $enrolment->student_id = $id;
        $enrolment->setRelation('student', $student);

        return $enrolment;
    }

    /**
     * @param  list<StudentEnrolment>  $enrolments
     */
    private function wire(array $enrolments, array $enrolledInTarget = [], int $seatsTaken = 0): MockInterface
    {
        $this->mock(AcademicYearRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('findOrFail')->with(1)->andReturn($this->from);
            $mock->shouldReceive('findOrFail')->with(2)->andReturn($this->to);
        });
        $this->mock(PromotionRepositoryInterface::class, function (MockInterface $mock) use ($enrolments, $enrolledInTarget) {
            $mock->shouldReceive('activeEnrolments')->andReturn(new Collection($enrolments));
            $mock->shouldReceive('enrolledStudentIds')->andReturn($enrolledInTarget);
        });
        $this->mock(EnrolmentService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sectionClosedReason')->andReturn(null)->byDefault();
            $mock->shouldReceive('placementErrors')->andReturn([])->byDefault();
        });

        return $this->mock(StudentEnrolmentRepositoryInterface::class, function (MockInterface $mock) use ($seatsTaken) {
            $mock->shouldReceive('lockSection')->andReturnUsing(fn (int $id) => $this->sections[$id])->byDefault();
            $mock->shouldReceive('countActiveInSection')->andReturn($seatsTaken)->byDefault();
        });
    }

    private function body(int $sourceId, ?int $defaultId, array $exceptions = []): array
    {
        return [
            'from_academic_year_id' => 1,
            'to_academic_year_id' => 2,
            'section_id' => $sourceId,
            'default_target_section_id' => $defaultId,
            'exceptions' => $exceptions,
        ];
    }

    private function assertInvalid(array $body, string $key): void
    {
        try {
            app(PromotionService::class)->apply($body);
            $this->fail("Expected a validation error for {$key}.");
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    public function test_the_source_and_target_sections_are_locked_in_one_ascending_id_pass(): void
    {
        $this->section(50, 6);
        $this->section(30, 7);
        $this->section(20, 7);
        $this->section(40, 7);
        $enrolments = [$this->enrolment(1), $this->enrolment(2), $this->enrolment(3)];

        $repo = $this->wire($enrolments);
        $repo->shouldReceive('lockSection')->once()->with(20)->ordered()->andReturn($this->sections[20]);
        $repo->shouldReceive('lockSection')->once()->with(30)->ordered()->andReturn($this->sections[30]);
        $repo->shouldReceive('lockSection')->once()->with(40)->ordered()->andReturn($this->sections[40]);
        $repo->shouldReceive('lockSection')->once()->with(50)->ordered()->andReturn($this->sections[50]);
        $repo->shouldReceive('update')->times(3);
        $this->acceptingEnrolments();

        app(PromotionService::class)->apply($this->body(50, 30, [
            ['student_id' => 2, 'action' => 'promote', 'target_section_id' => 40],
            ['student_id' => 3, 'action' => 'promote', 'target_section_id' => 20],
        ]));

        $this->addToAssertionCount(1); // the ordered() expectations are the assertion
    }

    public function test_skip_writes_nothing_and_an_already_enrolled_student_can_be_skipped(): void
    {
        $this->section(50, 6);
        $this->section(60, 7);
        $repo = $this->wire([$this->enrolment(1), $this->enrolment(2)], enrolledInTarget: [1]);
        $repo->shouldNotReceive('update');
        $enrolments = $this->mock(EnrolmentService::class);
        $enrolments->shouldReceive('sectionClosedReason')->andReturn(null);
        $enrolments->shouldReceive('placementErrors')->andReturn([]);
        $enrolments->shouldReceive('save')->never();

        $result = app(PromotionService::class)->apply($this->body(50, null, [
            ['student_id' => 1, 'action' => 'skip'], ['student_id' => 2, 'action' => 'skip'],
        ]));

        $this->assertSame(['promoted' => 0, 'retained' => 0, 'left' => 0, 'graduated' => 0, 'skipped' => 2], $result['summary']);
        $this->assertSame([], $result['target_sections']);
    }

    public function test_new_enrolments_are_created_from_the_target_years_start_date(): void
    {
        $this->section(50, 6);
        $this->section(60, 7);
        $repo = $this->wire([$this->enrolment(1)]);
        $repo->shouldReceive('update')->once();
        $enrolments = $this->mock(EnrolmentService::class);
        $enrolments->shouldReceive('sectionClosedReason')->andReturn(null);
        $enrolments->shouldReceive('placementErrors')->andReturn([]);
        $enrolments->shouldReceive('save')->once()
            ->withArgs(fn ($student, $year, array $data) => $data['enrolled_on'] === '2027-01-01')
            ->andReturn(new StudentEnrolment);

        app(PromotionService::class)->apply($this->body(50, 60));
    }

    /** Re-binds EnrolmentService to a mock that accepts the calls a successful batch makes. */
    private function acceptingEnrolments(): void
    {
        $this->mock(EnrolmentService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sectionClosedReason')->andReturn(null);
            $mock->shouldReceive('placementErrors')->andReturn([]);
            $mock->shouldReceive('save')->andReturn(new StudentEnrolment);
        });
    }

    public function test_a_target_year_that_is_not_later_fails_before_any_lock(): void
    {
        $this->section(50, 6);
        $repo = $this->wire([]);
        $repo->shouldNotReceive('lockSection');

        $this->assertInvalid(array_replace($this->body(50, null), ['to_academic_year_id' => 1]), 'to_academic_year_id');
    }

    public function test_the_target_section_must_be_in_the_next_class_for_a_promotion_and_the_same_class_for_a_retain(): void
    {
        $this->section(50, 6);
        $this->section(60, 8);
        $repo = $this->wire([$this->enrolment(1)]);
        $repo->shouldNotReceive('create')->shouldNotReceive('update');

        $this->assertInvalid($this->body(50, 60), 'default_target_section_id');

        $this->section(70, 7);
        $this->assertInvalid($this->body(50, 70, [['student_id' => 1, 'action' => 'retain', 'target_section_id' => 70]]), 'exceptions.0.target_section_id');
    }

    public function test_the_whole_batch_must_fit_the_target_capacity_and_nothing_is_written_otherwise(): void
    {
        $this->section(50, 6);
        $this->section(60, 7, ['capacity' => 3]);
        $repo = $this->wire([$this->enrolment(1), $this->enrolment(2)], [], seatsTaken: 2);
        $repo->shouldNotReceive('update');

        $this->mock(StudentService::class)->shouldNotReceive('changeStatus');

        $this->assertInvalid($this->body(50, 60), 'default_target_section_id');
    }

    public function test_students_already_enrolled_in_the_target_year_are_reported(): void
    {
        $this->section(50, 6);
        $this->section(60, 7);
        $repo = $this->wire([$this->enrolment(1), $this->enrolment(2)], [2]);
        $repo->shouldNotReceive('update');

        $this->assertInvalid($this->body(50, 60), 'already_enrolled');
        $this->assertInvalid($this->body(50, 60, [['student_id' => 2, 'action' => 'promote']]), 'exceptions.0.student_id');
    }

    public function test_exceptions_for_unknown_or_repeated_students_are_rejected(): void
    {
        $this->section(50, 6);
        $this->section(60, 7);
        $this->wire([$this->enrolment(1)]);

        $this->assertInvalid($this->body(50, 60, [['student_id' => 9, 'action' => 'leave']]), 'exceptions.0.student_id');
        $this->assertInvalid($this->body(50, 60, [
            ['student_id' => 1, 'action' => 'leave'], ['student_id' => 1, 'action' => 'retain'],
        ]), 'exceptions.1.student_id');
    }

    public function test_entering_a_grouped_class_without_a_group_reports_the_student_names(): void
    {
        $this->section(50, 8);
        $this->section(60, 9);
        $this->wire([$this->enrolment(1)]);

        $this->assertInvalid($this->body(50, 60), 'missing_groups');
        $this->assertInvalid($this->body(50, 60, [['student_id' => 1, 'action' => 'promote']]), 'exceptions.0.group');
    }

    public function test_placement_errors_are_reported_on_the_students_row(): void
    {
        $this->section(50, 9);
        $this->section(60, 10);
        $this->wire([$this->enrolment(1, ['group' => 'science', 'optional_subject_id' => 5])]);
        $this->mock(EnrolmentService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sectionClosedReason')->andReturn(null);
            $mock->shouldReceive('placementErrors')->once()->with($this->sections[60], 'science', 5)
                ->andReturn(['enrolment.optional_subject_id' => ['Not an optional subject.']]);
        });

        $this->assertInvalid($this->body(50, 60, [['student_id' => 1, 'action' => 'promote']]), 'exceptions.0.optional_subject_id');
    }

    public function test_a_closed_target_section_is_rejected(): void
    {
        $this->section(50, 6);
        $this->section(60, 7);
        $this->wire([$this->enrolment(1)]);
        $this->mock(EnrolmentService::class, function (MockInterface $mock) {
            $mock->shouldReceive('sectionClosedReason')->andReturn("The section's shift is not active.");
        });

        $this->assertInvalid($this->body(50, 60), 'default_target_section_id');
    }

    public function test_leavers_and_graduates_go_through_the_student_status_change_with_the_dhaka_date(): void
    {
        Carbon::setTestNow('2026-12-31 20:00:00'); // 2027-01-01 in Asia/Dhaka
        $this->section(50, 12);
        $this->wire([$this->enrolment(1), $this->enrolment(2)]);
        $enrolments = $this->mock(EnrolmentService::class);
        $enrolments->shouldReceive('syncStatus')->twice();

        $students = $this->mock(StudentService::class);
        $students->shouldReceive('leavingDateErrors')->andReturn([]);
        $students->shouldReceive('changeStatus')->once()->with(\Mockery::type(Student::class), Student::STATUS_GRADUATED, '2027-01-01')->andReturnUsing(fn ($s) => $s);
        $students->shouldReceive('changeStatus')->once()->with(\Mockery::type(Student::class), Student::STATUS_LEFT, '2027-01-01')->andReturnUsing(fn ($s) => $s);

        $result = app(PromotionService::class)->apply($this->body(50, null, [['student_id' => 2, 'action' => 'leave']]));

        $this->assertSame(['promoted' => 0, 'retained' => 0, 'left' => 1, 'graduated' => 1, 'skipped' => 0], $result['summary']);
        Carbon::setTestNow();
    }

    public function test_retaining_into_a_nearly_full_source_section_names_the_section_and_writes_nothing(): void
    {
        $this->section(50, 6, ['name' => 'Section A']);
        $this->section(60, 7);
        $repo = $this->wire([$this->enrolment(1), $this->enrolment(2)], [], seatsTaken: 39);
        $repo->shouldNotReceive('update');
        $this->mock(StudentService::class)->shouldNotReceive('changeStatus');

        try {
            app(PromotionService::class)->apply($this->body(50, 60, [
                ['student_id' => 1, 'action' => 'retain'],
                ['student_id' => 2, 'action' => 'retain'],
            ]));
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertSame('Section A does not have enough free seats (1 free, 2 students).', $e->errors()['section_capacity'][0]);
            $this->assertArrayHasKey('exceptions.0.target_section_id', $e->errors());
            $this->assertArrayHasKey('exceptions.1.target_section_id', $e->errors());
        }
    }

    public function test_leaving_date_rule_failures_are_reported_before_any_write(): void
    {
        $this->section(50, 12);
        $repo = $this->wire([$this->enrolment(1), $this->enrolment(2)]);
        $repo->shouldNotReceive('update');

        $students = $this->mock(StudentService::class);
        $students->shouldReceive('leavingDateErrors')->andReturn(['leaving_date' => ['The leaving date must be on or after the admission date.']]);
        $students->shouldNotReceive('changeStatus');

        $this->assertInvalid($this->body(50, null, [['student_id' => 1, 'action' => 'leave']]), 'exceptions.0.action');
        $this->assertInvalid($this->body(50, null, [['student_id' => 1, 'action' => 'leave']]), 'graduates');
    }

    public function test_a_leaver_already_enrolled_in_the_target_year_is_rejected(): void
    {
        $this->section(50, 6);
        $repo = $this->wire([$this->enrolment(1)], [1]);
        $repo->shouldNotReceive('update');
        $this->mock(StudentService::class)->shouldNotReceive('changeStatus');

        $this->assertInvalid($this->body(50, null, [['student_id' => 1, 'action' => 'leave']]), 'exceptions.0.student_id');
    }
}
