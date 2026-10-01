<?php

namespace Tests\Unit;

use App\Models\Student;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\FeePaymentService;
use App\Services\FeeReportService;
use App\Services\PortalService;
use App\Services\ResultService;
use App\Services\StudentService;
use Illuminate\Database\Eloquent\Collection;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The portal only chooses between the own-record and child-record service methods that
 * /api/my/* uses; the services are mocked here (no database).
 */
class PortalServiceTest extends TestCase
{
    private function user(string $role): User
    {
        $user = \Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('hasRole')->andReturnUsing(fn ($r) => $r === $role);
        $user->id = 5;

        return $user;
    }

    private function student(int $id): Student
    {
        $student = new Student;
        $student->id = $id;

        return $student;
    }

    private function service(?MockInterface &$students = null, ?MockInterface &$attendance = null): PortalService
    {
        $students = $this->mock(StudentService::class);
        $attendance = $this->mock(AttendanceService::class);
        $this->mock(ResultService::class);
        $this->mock(FeeReportService::class);
        $this->mock(FeePaymentService::class);

        return app(PortalService::class);
    }

    public function test_a_student_always_gets_their_own_record_whatever_is_requested(): void
    {
        $user = $this->user('student');
        $own = $this->student(1);
        $service = $this->service($students);
        $students->shouldReceive('findOwn')->once()->with($user)->andReturn($own);
        $students->shouldNotReceive('findChildOf');

        $this->assertSame($own, $service->resolveStudent($user, 99, 98));
    }

    public function test_a_guardian_gets_the_requested_child_through_the_ownership_check(): void
    {
        $user = $this->user('parent');
        $child = $this->student(7);
        $service = $this->service($students);
        $students->shouldReceive('findChildOf')->once()->with($user, 7)->andReturn($child);
        $students->shouldNotReceive('childrenOf');

        $this->assertSame($child, $service->resolveStudent($user, 7, null));
    }

    public function test_a_guardian_falls_back_to_the_remembered_then_the_first_child(): void
    {
        $user = $this->user('parent');
        $first = $this->student(1);
        $second = $this->student(2);
        $service = $this->service($students);
        $students->shouldReceive('childrenOf')->with($user)->andReturn(new Collection([$first, $second]));

        $this->assertSame($second, $service->resolveStudent($user, null, 2));
        // A remembered child that is no longer theirs is ignored.
        $this->assertSame($first, $service->resolveStudent($user, null, 99));
        $this->assertSame($first, $service->resolveStudent($user, null, null));
    }

    public function test_a_guardian_without_children_gets_404_and_other_roles_403(): void
    {
        $user = $this->user('parent');
        $service = $this->service($students);
        $students->shouldReceive('childrenOf')->andReturn(new Collection);

        try {
            $service->resolveStudent($user, null, null);
            $this->fail('Expected a 404');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }

        try {
            $service->resolveStudent($this->user('teacher'), null, null);
            $this->fail('Expected a 403');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_attendance_uses_own_month_for_a_student_and_child_month_for_a_guardian(): void
    {
        $service = $this->service($students, $attendance);
        $student = $this->user('student');
        $guardian = $this->user('parent');
        $attendance->shouldReceive('ownMonth')->once()->with($student, '2026-10')->andReturn(['month' => '2026-10']);
        $attendance->shouldReceive('childMonth')->once()->with($guardian, 7, '2026-10')->andReturn(['month' => '2026-10']);

        $this->assertSame('2026-10', $service->attendance($student, $this->student(1), '2026-10')['month']);
        $this->assertSame('2026-10', $service->attendance($guardian, $this->student(7), '2026-10')['month']);
    }

    public function test_a_student_has_no_children_for_the_switcher(): void
    {
        $service = $this->service($students);
        $students->shouldNotReceive('childrenOf');

        $this->assertCount(0, $service->children($this->user('student')));
    }
}
