<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\ClassSection;
use App\Models\Staff;
use App\Models\SubjectAssignment;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\ClassTeacherRepositoryInterface;
use App\Repositories\Contracts\StaffRepositoryInterface;
use App\Repositories\Contracts\SubjectAssignmentRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\TeacherScope;
use Illuminate\Database\Eloquent\Collection;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * TeacherScope resolves what a teacher teaches and leads, against mocked repository
 * interfaces (no database).
 */
class TeacherScopeTest extends TestCase
{
    private User $user;

    private Staff $staff;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User;
        $this->user->id = 10;
        $this->staff = new Staff;
        $this->staff->id = 20;
        $this->year = new AcademicYear;
        $this->year->id = 1;
    }

    private function assignment(int $section, int $class, int $subject): SubjectAssignment
    {
        return new SubjectAssignment(['section_id' => $section, 'class_id' => $class, 'subject_id' => $subject]);
    }

    /**
     * @param  list<string>  $roles
     */
    private function mockRepositories(array $roles, ?Staff $staff, array $assignments = [], array $classSections = [], ?AcademicYear $active = null): void
    {
        $this->mock(UserRepositoryInterface::class, function (MockInterface $mock) use ($roles) {
            $mock->shouldReceive('hasRole')->andReturnUsing(fn ($user, $role) => in_array($role, $roles, true));
        });
        $this->mock(StaffRepositoryInterface::class, function (MockInterface $mock) use ($staff) {
            $mock->shouldReceive('findByUserId')->with(10)->andReturn($staff);
        });
        $this->mock(AcademicYearRepositoryInterface::class, function (MockInterface $mock) use ($active) {
            $mock->shouldReceive('findActive')->andReturn($active);
        });
        $this->mock(SubjectAssignmentRepositoryInterface::class, function (MockInterface $mock) use ($assignments) {
            $mock->shouldReceive('forStaffAndYear')->with(20, 1)->andReturn(new Collection($assignments));
        });
        $this->mock(ClassTeacherRepositoryInterface::class, function (MockInterface $mock) use ($classSections) {
            $mock->shouldReceive('forStaffAndYear')->with(20, 1)->andReturn(new Collection($classSections));
        });
    }

    public function test_a_teacher_is_scoped_but_an_admin_or_other_role_is_not(): void
    {
        $scope = fn (array $roles) => $this->mockRepositories($roles, null) ?? app(TeacherScope::class)->isScoped($this->user);

        $this->assertTrue($scope(['teacher']));
        $this->assertFalse($scope(['admin']));
        $this->assertFalse($scope(['teacher', 'admin']));
        $this->assertFalse($scope(['office']));
    }

    public function test_the_context_holds_the_teaching_and_leading_sections_of_the_active_year(): void
    {
        $this->mockRepositories(
            ['teacher'],
            $this->staff,
            [$this->assignment(5, 9, 100), $this->assignment(5, 9, 101), $this->assignment(6, 9, 100)],
            [new ClassSection(['section_id' => 7, 'class_id' => 7, 'academic_year_id' => 1, 'staff_id' => 20]), new ClassSection(['section_id' => 5, 'class_id' => 9, 'academic_year_id' => 1, 'staff_id' => 20])],
            $this->year,
        );

        $context = app(TeacherScope::class)->forUser($this->user);

        $this->assertSame($this->staff, $context->staff);
        $this->assertSame($this->year, $context->year);
        $this->assertSame([5, 6], $context->teachingSectionIds());
        $this->assertSame([7, 5], $context->leadingSectionIds());
        $this->assertEqualsCanonicalizing([5, 6, 7], $context->sectionIds());
        $this->assertSame(
            [['class_id' => 9, 'subject_id' => 100], ['class_id' => 9, 'subject_id' => 101]],
            $context->classSubjectPairs()
        );
    }

    public function test_a_given_year_is_used_instead_of_the_active_one(): void
    {
        $this->mockRepositories(['teacher'], $this->staff, [$this->assignment(5, 9, 100)], [], null);
        $this->mock(AcademicYearRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('findOrFail')->with(1)->andReturn($this->year);
            $mock->shouldNotReceive('findActive');
        });

        $this->assertSame([5], app(TeacherScope::class)->forUser($this->user, 1)->sectionIds());
    }

    public function test_a_user_with_no_staff_link_has_no_context_and_an_empty_scope(): void
    {
        $this->mockRepositories(['teacher'], null);

        $scope = app(TeacherScope::class);

        $this->assertNull($scope->forUser($this->user));
        $this->assertSame([], $scope->sectionIdsFor($this->user));
        $this->assertSame([], $scope->classSubjectPairsFor($this->user));
    }

    public function test_without_an_academic_year_the_scope_is_empty(): void
    {
        $this->mockRepositories(['teacher'], $this->staff, [], [], null);

        $context = app(TeacherScope::class)->forUser($this->user);

        $this->assertNull($context->year);
        $this->assertSame([], $context->sectionIds());
    }

    public function test_an_unscoped_user_gets_null_so_nothing_is_restricted(): void
    {
        $this->mockRepositories(['admin'], $this->staff, [$this->assignment(5, 9, 100)], [], $this->year);

        $scope = app(TeacherScope::class);

        $this->assertNull($scope->sectionIdsFor($this->user));
        $this->assertNull($scope->classSubjectPairsFor($this->user));
    }
}
