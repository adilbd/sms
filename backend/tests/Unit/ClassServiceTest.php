<?php

namespace Tests\Unit;

use App\Models\Classes;
use App\Repositories\Contracts\ClassRepositoryInterface;
use App\Services\ClassService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface — no database.
 */
class ClassServiceTest extends TestCase
{
    public function test_delete_is_refused_when_class_has_sections(): void
    {
        $class = new Classes;

        $this->mock(ClassRepositoryInterface::class, function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('hasSections')->once()->with($class)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(ClassService::class)->delete($class));
    }

    public function test_delete_is_refused_when_class_has_students(): void
    {
        $class = new Classes;

        $this->mock(ClassRepositoryInterface::class, function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('hasSections')->once()->with($class)->andReturn(false);
            $mock->shouldReceive('hasStudents')->once()->with($class)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(ClassService::class)->delete($class));
    }

    public function test_delete_is_refused_when_class_has_attendances(): void
    {
        $class = new Classes;

        $this->mock(ClassRepositoryInterface::class, function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('hasSections')->once()->andReturn(false);
            $mock->shouldReceive('hasStudents')->once()->andReturn(false);
            $mock->shouldReceive('hasAttendances')->once()->with($class)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(ClassService::class)->delete($class));
    }

    public function test_delete_is_refused_when_class_has_exam_schedules(): void
    {
        $class = new Classes;

        $this->mock(ClassRepositoryInterface::class, function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('hasSections')->once()->andReturn(false);
            $mock->shouldReceive('hasStudents')->once()->andReturn(false);
            $mock->shouldReceive('hasAttendances')->once()->andReturn(false);
            $mock->shouldReceive('hasExamSchedules')->once()->with($class)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(ClassService::class)->delete($class));
    }

    public function test_delete_is_refused_when_class_has_fee_structures(): void
    {
        $class = new Classes;

        $this->mock(ClassRepositoryInterface::class, function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('hasSections')->once()->andReturn(false);
            $mock->shouldReceive('hasStudents')->once()->andReturn(false);
            $mock->shouldReceive('hasAttendances')->once()->andReturn(false);
            $mock->shouldReceive('hasExamSchedules')->once()->andReturn(false);
            $mock->shouldReceive('hasFeeStructures')->once()->with($class)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(ClassService::class)->delete($class));
    }

    public function test_delete_is_refused_when_class_has_subject_assignments(): void
    {
        $class = new Classes;

        $this->mock(ClassRepositoryInterface::class, function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('hasSections')->once()->andReturn(false);
            $mock->shouldReceive('hasStudents')->once()->andReturn(false);
            $mock->shouldReceive('hasAttendances')->once()->andReturn(false);
            $mock->shouldReceive('hasExamSchedules')->once()->andReturn(false);
            $mock->shouldReceive('hasFeeStructures')->once()->andReturn(false);
            $mock->shouldReceive('hasSubjectAssignments')->once()->with($class)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(ClassService::class)->delete($class));
    }

    public function test_delete_removes_an_unused_class(): void
    {
        $class = new Classes;

        $this->mock(ClassRepositoryInterface::class, function (MockInterface $mock) use ($class) {
            $mock->shouldReceive('hasSections')->once()->andReturn(false);
            $mock->shouldReceive('hasStudents')->once()->andReturn(false);
            $mock->shouldReceive('hasAttendances')->once()->andReturn(false);
            $mock->shouldReceive('hasExamSchedules')->once()->andReturn(false);
            $mock->shouldReceive('hasFeeStructures')->once()->andReturn(false);
            $mock->shouldReceive('hasSubjectAssignments')->once()->andReturn(false);
            $mock->shouldReceive('delete')->once()->with($class);
        });

        app(ClassService::class)->delete($class);
    }

    public function test_create_reports_a_concurrent_duplicate_number_as_a_validation_error(): void
    {
        $this->mock(ClassRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('create')->once()->andThrow(
                new UniqueConstraintViolationException('sqlite', 'insert into classes', [], new RuntimeException('UNIQUE constraint failed: classes.number'))
            );
        });

        try {
            app(ClassService::class)->create(['number' => 5, 'name' => 'Class 5', 'code' => 'C05']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('number', $e->errors());
        }
    }

    private function assertConflict(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected a 409 HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }
}
