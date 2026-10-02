<?php

namespace Tests\Unit;

use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Homework;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\ClassSubjectRepositoryInterface;
use App\Repositories\Contracts\HomeworkRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Repositories\Contracts\SubjectAssignmentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\HomeworkService;
use App\Services\TeacherScope;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The homework rules against mocked repositories: no database. "Now" is 2026-10-15 12:00
 * Asia/Dhaka.
 */
class HomeworkServiceTest extends TestCase
{
    private MockInterface $homework;

    private MockInterface $sections;

    private MockInterface $assignments;

    private MockInterface $curriculum;

    private MockInterface $staff;

    private MockInterface $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-15 06:00:00');

        $this->homework = $this->mock(HomeworkRepositoryInterface::class);
        $this->sections = $this->mock(SectionRepositoryInterface::class);
        $this->assignments = $this->mock(SubjectAssignmentRepositoryInterface::class);
        $this->curriculum = $this->mock(ClassSubjectRepositoryInterface::class);
        $this->staff = $this->mock(StaffRepositoryInterface::class);
        $this->users = $this->mock(UserRepositoryInterface::class);
        $this->mock(AcademicYearRepositoryInterface::class);
        $this->mock(TeacherScope::class);
    }

    private function service(): HomeworkService
    {
        return app(HomeworkService::class);
    }

    private function user(int $id = 7): User
    {
        return (new User)->forceFill(['id' => $id]);
    }

    private function section(?string $group = null): Section
    {
        return (new Section)->forceFill(['id' => 20, 'class_id' => 9, 'group' => $group]);
    }

    /** @return array<string, mixed> */
    private function data(array $extra = []): array
    {
        return ['academic_year_id' => 3, 'section_id' => 20, 'subject_id' => 5, 'title' => 'Exercises', 'due_on' => '2026-10-20', ...$extra];
    }

    private function expectYear(): void
    {
        $this->app->make(AcademicYearRepositoryInterface::class)->shouldReceive('findOrFail')->with(3)->andReturn((new \App\Models\AcademicYear)->forceFill(['id' => 3]));
    }

    public function test_an_unassigned_teacher_is_refused_before_anything_is_written(): void
    {
        $this->expectYear();
        $this->users->shouldReceive('hasRole')->with(\Mockery::type(User::class), 'admin')->andReturn(false);
        $this->sections->shouldReceive('findOrFail')->with(20)->andReturn($this->section());
        $this->assignments->shouldReceive('userHoldsAssignment')->once()->with(7, 20, 5, 3)->andReturn(false);
        $this->homework->shouldNotReceive('create');

        try {
            $this->service()->create($this->data(), null, $this->user());
            $this->fail('Expected a 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_an_admin_skips_the_assignment_check_but_not_the_curriculum_check(): void
    {
        $this->expectYear();
        $this->users->shouldReceive('hasRole')->andReturn(true);
        $this->sections->shouldReceive('findOrFail')->with(20)->andReturn($this->section('science'));
        $this->assignments->shouldNotReceive('userHoldsAssignment');
        $this->assignments->shouldReceive('curriculumSubjectIds')->once()->with(9, 'science')->andReturn([1, 2]);
        $this->homework->shouldNotReceive('create');

        $this->expectException(ValidationException::class);

        try {
            $this->service()->create($this->data(), null, $this->user());
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('subject_id', $e->errors());

            throw $e;
        }
    }

    public function test_a_due_date_before_the_assigned_date_is_refused(): void
    {
        $this->expectYear();
        $this->users->shouldReceive('hasRole')->andReturn(true);
        $this->sections->shouldReceive('findOrFail')->andReturn($this->section());
        $this->assignments->shouldReceive('curriculumSubjectIds')->andReturn([5]);
        $this->homework->shouldNotReceive('create');

        try {
            $this->service()->create($this->data(['assigned_on' => '2026-10-10', 'due_on' => '2026-10-09']), null, $this->user());
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('due_on', $e->errors());
        }
    }

    public function test_the_assigned_date_defaults_to_today_in_dhaka_and_the_author_is_the_users_staff(): void
    {
        $this->expectYear();
        $this->users->shouldReceive('hasRole')->andReturn(false);
        $this->sections->shouldReceive('findOrFail')->andReturn($this->section());
        $this->assignments->shouldReceive('userHoldsAssignment')->andReturn(true);
        $this->assignments->shouldReceive('curriculumSubjectIds')->andReturn([5]);
        $this->staff->shouldReceive('findByUserId')->with(7)->andReturn((new Staff)->forceFill(['id' => 11]));
        $this->homework->shouldReceive('create')->once()->withArgs(fn (array $attributes) => $attributes['assigned_on'] === '2026-10-15'
            && $attributes['staff_id'] === 11
            && $attributes['details'] === null
            && $attributes['academic_year_id'] === 3)
            ->andReturn($this->loadable());

        $this->service()->create($this->data(), null, $this->user());
    }

    /** A homework whose relations load without a database. */
    private function loadable(array $attributes = []): Homework
    {
        $homework = \Mockery::mock(Homework::class)->makePartial();
        $homework->shouldReceive('load')->andReturnSelf();

        return $homework->forceFill($attributes);
    }

    private function saved(string $due, int $author = 11): Homework
    {
        return $this->loadable([
            'id' => 1, 'staff_id' => $author, 'section_id' => 20, 'subject_id' => 5, 'academic_year_id' => 3,
            'assigned_on' => '2026-08-01', 'due_on' => $due,
        ]);
    }

    public function test_the_author_may_edit_through_the_due_date_but_not_after(): void
    {
        $this->users->shouldReceive('hasRole')->andReturn(false);
        $this->staff->shouldReceive('findByUserId')->with(7)->andReturn((new Staff)->forceFill(['id' => 11]));
        $this->assignments->shouldReceive('userHoldsAssignment')->andReturn(true);
        $this->homework->shouldReceive('update')->once()->andReturnUsing(fn (Homework $homework) => $homework);

        $this->service()->update($this->saved('2026-10-15'), ['title' => 'Last day'], null, false, $this->user());

        $this->expectException(HttpException::class);
        $this->service()->update($this->saved('2026-10-14'), ['title' => 'Too late'], null, false, $this->user());
    }

    public function test_someone_elses_homework_is_refused(): void
    {
        $this->users->shouldReceive('hasRole')->andReturn(false);
        $this->staff->shouldReceive('findByUserId')->with(7)->andReturn((new Staff)->forceFill(['id' => 12]));
        $this->homework->shouldNotReceive('update');
        $this->homework->shouldNotReceive('delete');

        try {
            $this->service()->delete($this->saved('2026-10-20'), $this->user());
            $this->fail('Expected a 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_an_admin_edits_after_the_due_date(): void
    {
        $this->users->shouldReceive('hasRole')->andReturn(true);
        $this->staff->shouldNotReceive('findByUserId');
        $this->homework->shouldReceive('update')->once()->andReturnUsing(fn (Homework $homework) => $homework);

        $this->service()->update($this->saved('2026-09-01'), ['title' => 'Admin'], null, false, $this->user());
    }

    public function test_the_due_date_is_checked_against_the_saved_assigned_date_on_a_partial_update(): void
    {
        $this->users->shouldReceive('hasRole')->andReturn(true);
        $this->homework->shouldNotReceive('update');

        try {
            $this->service()->update($this->saved('2026-10-20'), ['due_on' => '2026-07-30'], null, false, $this->user());
            $this->fail('Expected a validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('due_on', $e->errors());
        }
    }

    private function student(?string $group, ?int $optionalSubjectId): Student
    {
        $enrolment = (new StudentEnrolment)->forceFill([
            'section_id' => 20, 'academic_year_id' => 3, 'class_id' => 9, 'group' => $group, 'optional_subject_id' => $optionalSubjectId,
        ]);
        $enrolment->setRelation('class', (new Classes)->forceFill(['id' => 9]));

        return (new Student)->setRelation('currentEnrolment', $enrolment);
    }

    private function row(int $subjectId, string $type, ?string $group): ClassSubject
    {
        return (new ClassSubject)->forceFill(['class_id' => 9, 'subject_id' => $subjectId, 'type' => $type, 'group' => $group, 'choice_group' => null]);
    }

    public function test_a_student_gets_only_the_subjects_they_take(): void
    {
        $rows = new Collection([
            $this->row(1, 'compulsory', null),
            $this->row(2, 'compulsory', 'science'),
            $this->row(3, 'compulsory', 'business_studies'),
            $this->row(4, 'optional', 'science'),
            $this->row(5, 'optional', 'science'),
        ]);
        $this->curriculum->shouldReceive('forClass')->once()->andReturn($rows);
        $this->homework->shouldReceive('forSectionSubjects')->once()
            ->withArgs(fn (int $section, int $year, array $subjectIds, array $filters) => $section === 20 && $year === 3 && $subjectIds === [1, 2, 4])
            ->andReturn(new Collection);

        $this->service()->forStudent($this->student('science', 4));
    }

    public function test_a_student_without_an_enrolment_gets_nothing_and_no_query_runs(): void
    {
        $this->curriculum->shouldNotReceive('forClass');
        $this->homework->shouldNotReceive('forSectionSubjects');

        $this->assertCount(0, $this->service()->forStudent(new Student));
    }

    public function test_due_this_week_is_today_through_six_days_ahead(): void
    {
        $this->curriculum->shouldReceive('forClass')->andReturn(new Collection([$this->row(1, 'compulsory', null)]));
        $this->homework->shouldReceive('forSectionSubjects')->once()
            ->withArgs(fn (int $section, int $year, array $ids, array $filters) => $filters['from'] === '2026-10-15' && $filters['to'] === '2026-10-21')
            ->andReturn(new Collection);

        $this->service()->dueThisWeek($this->student(null, null));
    }
}
