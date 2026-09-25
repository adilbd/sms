<?php

namespace Tests\Unit;

use App\Models\Subject;
use App\Repositories\Contracts\SubjectRepositoryInterface;
use App\Services\SubjectService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface — no database.
 */
class SubjectServiceTest extends TestCase
{
    public function test_delete_is_refused_when_subject_is_used_in_exam_schedules(): void
    {
        $subject = new Subject;

        $this->mock(SubjectRepositoryInterface::class, function (MockInterface $mock) use ($subject) {
            $mock->shouldReceive('isUsedInExamSchedules')->once()->with($subject)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(SubjectService::class)->delete($subject));
    }

    public function test_delete_is_refused_when_subject_has_teacher_assignments(): void
    {
        $subject = new Subject;

        $this->mock(SubjectRepositoryInterface::class, function (MockInterface $mock) use ($subject) {
            $mock->shouldReceive('isUsedInExamSchedules')->once()->with($subject)->andReturn(false);
            $mock->shouldReceive('hasTeacherAssignments')->once()->with($subject)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(SubjectService::class)->delete($subject));
    }

    public function test_delete_removes_unused_subject(): void
    {
        $subject = new Subject;

        $this->mock(SubjectRepositoryInterface::class, function (MockInterface $mock) use ($subject) {
            $mock->shouldReceive('isUsedInExamSchedules')->once()->with($subject)->andReturn(false);
            $mock->shouldReceive('hasTeacherAssignments')->once()->with($subject)->andReturn(false);
            $mock->shouldReceive('delete')->once()->with($subject);
        });

        app(SubjectService::class)->delete($subject);
    }

    public function test_create_reports_a_concurrent_duplicate_code_as_a_validation_error(): void
    {
        $this->mock(SubjectRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('create')->once()->andThrow(
                new UniqueConstraintViolationException('sqlite', 'insert into subjects', [], new RuntimeException('UNIQUE constraint failed'))
            );
        });

        try {
            app(SubjectService::class)->create(['name' => 'Math', 'code' => 'MATH']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('code', $e->errors());
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
