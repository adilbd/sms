<?php

namespace Tests\Unit;

use App\Models\Subject;
use App\Repositories\Contracts\SubjectRepositoryInterface;
use App\Services\SubjectService;
use Mockery\MockInterface;
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

        try {
            app(SubjectService::class)->delete($subject);
            $this->fail('Expected a 409 HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_delete_removes_unused_subject(): void
    {
        $subject = new Subject;

        $this->mock(SubjectRepositoryInterface::class, function (MockInterface $mock) use ($subject) {
            $mock->shouldReceive('isUsedInExamSchedules')->once()->with($subject)->andReturn(false);
            $mock->shouldReceive('delete')->once()->with($subject);
        });

        app(SubjectService::class)->delete($subject);
    }
}
