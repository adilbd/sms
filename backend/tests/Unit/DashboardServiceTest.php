<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Section;
use App\Models\User;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\DashboardRepositoryInterface;
use App\Repositories\Contracts\HolidayRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\DashboardService;
use App\Services\InstituteSettingsService;
use App\Services\TeacherScope;
use Illuminate\Database\Eloquent\Collection;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Role selection and number formatting, against mocked repositories (no database).
 */
class DashboardServiceTest extends TestCase
{
    private MockInterface $repo;

    private MockInterface $scope;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 12:00 in Dhaka.
        $this->travelTo('2026-10-15 06:00:00');
        $this->scope = $this->mock(TeacherScope::class);
        $this->mock(HolidayRepositoryInterface::class)->shouldReceive('findByDate')->andReturn(null);
        $this->mock(InstituteSettingsService::class)->shouldReceive('weeklyHolidays')->andReturn(['friday']);
        $this->mock(AcademicYearRepositoryInterface::class)->shouldReceive('findActive')->andReturn($this->year());
        $this->repo = $this->mock(DashboardRepositoryInterface::class);
    }

    private function year(): AcademicYear
    {
        return (new AcademicYear(['year' => 2026, 'name' => '2026']))->forceFill(['id' => 1]);
    }

    private function rolesOf(array $roles): User
    {
        $user = new User;
        $this->mock(UserRepositoryInterface::class)->shouldReceive('roleNames')->with($user)->andReturn($roles);

        return $user;
    }

    /** Mocks the user's roles first, then resolves the service so it gets that mock. */
    private function forRoles(array $roles): array
    {
        $user = $this->rolesOf($roles);

        return app(DashboardService::class)->forUser($user);
    }

    /** Every query the admin dashboard makes, empty. */
    private function emptyAdminQueries(): void
    {
        $this->repo->shouldReceive('sections')->andReturn(new Collection);
        $this->repo->shouldReceive('enrolmentGroups')->andReturn(collect());
        $this->repo->shouldReceive('staffCounts')->andReturn(['total' => 0, 'teachers' => 0, 'with_login' => 0]);
        $this->repo->shouldReceive('attendanceCounts')->andReturn(collect());
        $this->repo->shouldReceive('latestExam')->andReturn(null);
        $this->repo->shouldReceive('openExamSubjects')->andReturn(new Collection);
        $this->repo->shouldReceive('dueTotals')->andReturn(['net' => null, 'paid' => null]);
        $this->repo->shouldReceive('overdueTotals')->andReturn(['count' => 0, 'outstanding' => null]);
        $this->repo->shouldReceive('collectionByMethod')->andReturn(collect());
        $this->repo->shouldReceive('recentPayments')->andReturn(new Collection);
        $this->repo->shouldReceive('recentExams')->andReturn(new Collection);
        $this->repo->shouldReceive('recentStudents')->andReturn(new Collection);
    }

    public function test_a_user_who_is_admin_and_teacher_gets_the_admin_view(): void
    {
        $this->emptyAdminQueries();
        $this->scope->shouldNotReceive('forUser');

        $data = $this->forRoles(['teacher', 'admin']);

        $this->assertSame('admin', $data['role']);
        $this->assertSame(['role', 'today', 'academic_year', 'counts', 'attendance_today', 'exams', 'fees', 'recent'], array_keys($data));
        $this->assertSame('2026-10-15', $data['today']);
    }

    public function test_office_wins_over_teacher_and_sees_collections_only(): void
    {
        $this->repo->shouldReceive('dueTotals')->once()->andReturn(['net' => '2400.00', 'paid' => '800.25']);
        $this->repo->shouldReceive('collectionByMethod')->once()->andReturn(collect());
        $this->repo->shouldReceive('studentsWithOutstandingDues')->once()->andReturn(3);
        $this->repo->shouldReceive('recentPayments')->once()->with(10)->andReturn(new Collection);
        $this->repo->shouldNotReceive('sections');
        $this->scope->shouldNotReceive('forUser');

        $data = $this->forRoles(['teacher', 'office']);

        $this->assertSame('office', $data['role']);
        $this->assertSame(['role', 'today', 'academic_year', 'collection_today', 'month', 'students_with_outstanding_dues', 'recent_payments'], array_keys($data));
    }

    public function test_a_teacher_gets_the_teacher_view_scoped_by_the_teacher_scope(): void
    {
        $user = $this->rolesOf(['teacher']);
        $this->scope->shouldReceive('forUser')->once()->with($user, 1)->andReturn(null);
        $this->repo->shouldNotReceive('sections');
        $this->repo->shouldNotReceive('latestExam');

        $data = app(DashboardService::class)->forUser($user);

        $this->assertSame('teacher', $data['role']);
        $this->assertSame([], $data['mark_sheets']);
        $this->assertSame(['exam' => null, 'sections' => []], $data['pass_rates']);
        $this->assertSame([], $data['attendance_today']['sections']);
    }

    public function test_other_roles_are_refused(): void
    {
        $this->repo->shouldNotReceive('sections');

        try {
            $this->forRoles(['student']);
            $this->fail('Expected a 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_money_is_formatted_from_paisa_whatever_type_the_driver_returns(): void
    {
        // MySQL returns decimal strings; SQLite returns ints and floats.
        $this->repo->shouldReceive('dueTotals')->andReturn(['net' => 1600, 'paid' => 800.25]);
        $this->repo->shouldReceive('collectionByMethod')->andReturn(collect([
            (object) ['method' => 'cash', 'payments' => 2, 'total' => '1000.10'],
            (object) ['method' => 'bkash', 'payments' => 1, 'total' => 300.2],
        ]));
        $this->repo->shouldReceive('studentsWithOutstandingDues')->andReturn(0);
        $this->repo->shouldReceive('recentPayments')->andReturn(new Collection);

        $data = $this->forRoles(['office']);

        $this->assertSame(['month' => '2026-10', 'net_amount' => '1600.00', 'collected_amount' => '800.25', 'outstanding_amount' => '799.75'], $data['month']);
        $this->assertSame('1300.30', $data['collection_today']['amount']);
        $this->assertSame(3, $data['collection_today']['count']);
        $this->assertSame(
            ['cash' => '1000.10', 'bkash' => '300.20', 'nagad' => '0.00', 'rocket' => '0.00'],
            collect($data['collection_today']['by_method'])->pluck('amount', 'method')->all(),
        );
    }

    public function test_percentages_are_decimal_strings_and_late_counts_as_attended(): void
    {
        $section = (new Section(['name' => 'A', 'code' => 'A', 'is_active' => true]))->forceFill(['id' => 7, 'class_id' => 1, 'shift_id' => 1]);
        $section->setRelation('class', null)->setRelation('shift', null);
        $this->repo->shouldReceive('sections')->andReturn(new Collection([$section]));
        $this->repo->shouldReceive('enrolmentGroups')->andReturn(collect([(object) ['section_id' => 7, 'group' => null, 'optional_subject_id' => null, 'students' => 3]]));
        $this->repo->shouldReceive('attendanceCounts')->andReturn(collect([
            (object) ['section_id' => 7, 'status' => 'present', 'total' => 1],
            (object) ['section_id' => 7, 'status' => 'late', 'total' => 1],
            (object) ['section_id' => 7, 'status' => 'absent', 'total' => 1],
        ]));
        // Mockery uses the first matching expectation, so these defaults come last.
        $this->emptyAdminQueries();

        $attendance = $this->forRoles(['admin'])['attendance_today'];

        $this->assertSame('66.67', $attendance['percentage']);
        $this->assertSame('66.67', $attendance['sections'][0]['percentage']);
        $this->assertSame(1, $attendance['sections_marked']);
    }

    public function test_without_an_active_year_nothing_is_queried(): void
    {
        $this->mock(AcademicYearRepositoryInterface::class)->shouldReceive('findActive')->andReturn(null);
        $this->repo->shouldNotReceive('sections');

        $data = $this->forRoles(['admin']);

        $this->assertSame(['role' => 'admin', 'today' => '2026-10-15', 'academic_year' => null], $data);
    }
}
