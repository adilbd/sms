<?php

namespace Tests\Unit;

use App\Exceptions\ResultNotFoundException;
use App\Models\Classes;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Section;
use App\Repositories\Contracts\ExamResultRepositoryInterface;
use App\Services\ResultService;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * ResultService::publicLookup() against a mocked repository: no database.
 */
class ResultServicePublicLookupTest extends TestCase
{
    private function exam(): Exam
    {
        $exam = new Exam(['academic_year_id' => 3, 'status' => Exam::STATUS_PUBLISHED]);
        $exam->id = 8;

        return $exam;
    }

    private function section(int $classNumber): Section
    {
        $section = new Section;
        $section->id = 20;
        $section->setRelation('class', new Classes(['number' => $classNumber]));

        return $section;
    }

    private function service(callable $expect): ResultService
    {
        $this->mock(ExamResultRepositoryInterface::class, $expect);

        return app(ResultService::class);
    }

    private function idInput(array $overrides = []): array
    {
        return ['exam_id' => 8, 'student_id' => '20260047', 'date_of_birth' => '2012-03-15', ...$overrides];
    }

    private function assertNotFound(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected a ResultNotFoundException.');
        } catch (ResultNotFoundException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame(ResultNotFoundException::MESSAGE, $e->getMessage());
        }
    }

    public function test_it_finds_a_result_by_student_id_and_date_of_birth(): void
    {
        $result = new ExamResult;
        $exam = $this->exam();

        $service = $this->service(function (MockInterface $mock) use ($exam, $result) {
            $mock->shouldReceive('findPublishedExam')->once()->with(8)->andReturn($exam);
            $mock->shouldReceive('findForPublicLookup')->once()
                ->with($exam, ['student_code' => '20260047'], '2012-03-15')->andReturn($result);
        });

        $this->assertSame($result, $service->publicLookup($this->idInput(), '10.0.0.1'));
        $this->assertSame(0, RateLimiter::attempts(ResultService::publicFailureKey('10.0.0.1')));
    }

    public function test_it_finds_a_result_by_section_group_and_roll(): void
    {
        $result = new ExamResult;
        $exam = $this->exam();

        $service = $this->service(function (MockInterface $mock) use ($exam, $result) {
            $mock->shouldReceive('findPublishedExam')->andReturn($exam);
            $mock->shouldReceive('findSectionWithClass')->once()->with(20)->andReturn($this->section(9));
            $mock->shouldReceive('findForPublicLookup')->once()
                ->with($exam, ['section_id' => 20, 'group' => 'science', 'roll_number' => 5], '2012-03-15')->andReturn($result);
        });

        $found = $service->publicLookup(['exam_id' => 8, 'section_id' => 20, 'group' => 'science', 'roll' => 5, 'date_of_birth' => '2012-03-15'], '10.0.0.2');

        $this->assertSame($result, $found);
    }

    public function test_a_class_below_nine_looks_up_without_a_group(): void
    {
        $exam = $this->exam();

        $service = $this->service(function (MockInterface $mock) use ($exam) {
            $mock->shouldReceive('findPublishedExam')->andReturn($exam);
            $mock->shouldReceive('findSectionWithClass')->andReturn($this->section(5));
            $mock->shouldReceive('findForPublicLookup')->once()
                ->with($exam, ['section_id' => 20, 'group' => null, 'roll_number' => 5], '2012-03-15')->andReturn(new ExamResult);
        });

        $service->publicLookup(['exam_id' => 8, 'section_id' => 20, 'roll' => 5, 'date_of_birth' => '2012-03-15'], '10.0.0.3');

        $this->addToAssertionCount(1);
    }

    public function test_a_group_is_required_from_class_nine_and_forbidden_below(): void
    {
        $service = $this->service(function (MockInterface $mock) {
            $mock->shouldReceive('findPublishedExam')->andReturn($this->exam());
            $mock->shouldReceive('findSectionWithClass')->with(20)->andReturn($this->section(9));
            $mock->shouldReceive('findSectionWithClass')->with(21)->andReturn($this->section(8));
            $mock->shouldNotReceive('findForPublicLookup');
        });

        $input = ['exam_id' => 8, 'roll' => 5, 'date_of_birth' => '2012-03-15'];

        try {
            $service->publicLookup([...$input, 'section_id' => 20], '10.0.0.4');
            $this->fail('A Class 9 lookup needs a group.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('group', $e->errors());
        }

        try {
            $service->publicLookup([...$input, 'section_id' => 21, 'group' => 'science'], '10.0.0.4');
            $this->fail('A Class 8 lookup must not have a group.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('group', $e->errors());
        }

        // A bad group is a validation problem, not a failed lookup.
        $this->assertSame(0, RateLimiter::attempts(ResultService::publicFailureKey('10.0.0.4')));
    }

    public function test_every_miss_is_the_same_not_found_and_counts_as_a_failure(): void
    {
        $exam = $this->exam();

        $service = $this->service(function (MockInterface $mock) {
            // An unpublished or unknown exam.
            $mock->shouldReceive('findPublishedExam')->with(99)->andReturnNull();
            $mock->shouldReceive('findPublishedExam')->with(8)->andReturn($this->exam());
            // Wrong date of birth, unknown id or roll, or not enrolled that year.
            $mock->shouldReceive('findForPublicLookup')->andReturnNull();
            $mock->shouldReceive('findSectionWithClass')->with(404)->andReturnNull();
        });

        $ip = '10.0.0.5';
        $this->assertNotFound(fn () => $service->publicLookup($this->idInput(['exam_id' => 99]), $ip));
        $this->assertNotFound(fn () => $service->publicLookup($this->idInput(), $ip));
        $this->assertNotFound(fn () => $service->publicLookup(['exam_id' => 8, 'section_id' => 404, 'roll' => 1, 'date_of_birth' => '2012-03-15'], $ip));

        $this->assertSame(3, RateLimiter::attempts(ResultService::publicFailureKey($ip)));
        $this->assertNotNull($exam);
    }

    public function test_thirty_failures_lock_the_ip_with_retry_after_but_not_others(): void
    {
        $service = $this->service(function (MockInterface $mock) {
            $mock->shouldReceive('findPublishedExam')->andReturnNull();
        });

        for ($i = 0; $i < ResultService::MAX_PUBLIC_FAILURES; $i++) {
            $this->assertNotFound(fn () => $service->publicLookup($this->idInput(), '10.0.0.6'));
        }

        try {
            $service->publicLookup($this->idInput(), '10.0.0.6');
            $this->fail('The IP should be locked out.');
        } catch (ThrottleRequestsException $e) {
            $this->assertSame(429, $e->getStatusCode());
            $this->assertArrayHasKey('Retry-After', $e->getHeaders());
            $this->assertGreaterThan(0, (int) $e->getHeaders()['Retry-After']);
        }

        // Another IP is unaffected.
        $this->assertNotFound(fn () => $service->publicLookup($this->idInput(), '10.0.0.7'));
    }

    public function test_successful_lookups_never_count_toward_the_lock(): void
    {
        $exam = $this->exam();

        $service = $this->service(function (MockInterface $mock) use ($exam) {
            $mock->shouldReceive('findPublishedExam')->andReturn($exam);
            $mock->shouldReceive('findForPublicLookup')->andReturn(new ExamResult);
        });

        for ($i = 0; $i < ResultService::MAX_PUBLIC_FAILURES + 5; $i++) {
            $service->publicLookup($this->idInput(), '10.0.0.8');
        }

        $this->assertSame(0, RateLimiter::attempts(ResultService::publicFailureKey('10.0.0.8')));
    }
}
