<?php

namespace Tests\Unit;

use App\Models\Exam;
use App\Models\ExamSubject;
use App\Repositories\Contracts\ExamRepositoryInterface;
use App\Services\ExamScheduleService;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Services are unit-tested against mocked repository interfaces, no database.
 */
class ExamScheduleServiceTest extends TestCase
{
    private function exam(): Exam
    {
        $exam = new Exam;
        $exam->id = 8;

        return $exam;
    }

    private function subject(int $examId = 8, array $attributes = []): ExamSubject
    {
        $subject = new ExamSubject([
            'exam_id' => $examId, 'written_full' => 50, 'written_pass' => 17, 'mcq_full' => 25, 'mcq_pass' => 8, ...$attributes,
        ]);
        $subject->id = 40;
        $subject->exam_id = $examId;

        return $subject;
    }

    private function repo(callable $expect, ?ExamSubject $locked = null): void
    {
        $this->mock(ExamRepositoryInterface::class, function (MockInterface $m) use ($expect, $locked) {
            $m->shouldReceive('lockSubject')->andReturn($locked ?? $this->subject())->byDefault();
            $expect($m);
        });
    }

    /** @return array<string, list<string>> */
    private function errors(array $data): array
    {
        try {
            app(ExamScheduleService::class)->update($this->exam(), $this->subject(), $data);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            return $e->errors();
        }
    }

    private function assertStatus(int $status, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected a {$status} HttpException.");
        } catch (HttpException $e) {
            $this->assertSame($status, $e->getStatusCode());
        }
    }

    public function test_a_subject_of_another_exam_is_a_404(): void
    {
        $this->repo(fn (MockInterface $m) => $m->shouldNotReceive('lockSubject'));

        $this->assertStatus(404, fn () => app(ExamScheduleService::class)->update($this->exam(), $this->subject(examId: 99), ['exam_date' => '2026-06-02']));
    }

    public function test_a_date_change_never_touches_the_marks_rules(): void
    {
        $this->repo(function (MockInterface $m) {
            $m->shouldNotReceive('hasMarksForSubject');
            $m->shouldReceive('updateSubject')->once()->with(\Mockery::type(ExamSubject::class), ['exam_date' => '2026-06-02'])->andReturn($this->subject());
        });

        app(ExamScheduleService::class)->update($this->exam(), $this->subject(), ['exam_date' => '2026-06-02']);
    }

    public function test_part_rules_are_checked_against_the_subject_with_the_input_applied(): void
    {
        $this->repo(fn (MockInterface $m) => $m->shouldNotReceive('updateSubject'));

        // Only mcq_pass is sent: it exceeds the saved mcq_full (25).
        $this->assertSame(['mcq_pass'], array_keys($this->errors(['mcq_pass' => 26])));
        // A full mark without a pass mark (the part has none saved).
        $this->assertSame(['practical_pass'], array_keys($this->errors(['practical_full' => 25])));
        // Removing every part.
        $errors = $this->errors(['written_full' => null, 'written_pass' => null, 'mcq_full' => null, 'mcq_pass' => null]);
        $this->assertSame(['written_full'], array_keys($errors));
    }

    public function test_changing_the_marks_after_marks_exist_is_a_409(): void
    {
        $this->repo(function (MockInterface $m) {
            $m->shouldReceive('hasMarksForSubject')->once()->andReturn(true);
            $m->shouldNotReceive('updateSubject');
        });

        $this->assertStatus(409, fn () => app(ExamScheduleService::class)->update($this->exam(), $this->subject(), ['written_full' => 60]));
    }

    public function test_resending_the_same_marks_with_a_new_date_is_fine_once_marks_exist(): void
    {
        $this->repo(function (MockInterface $m) {
            $m->shouldNotReceive('hasMarksForSubject');
            $m->shouldReceive('updateSubject')->once()->andReturn($this->subject());
        });

        app(ExamScheduleService::class)->update($this->exam(), $this->subject(), ['written_full' => 50, 'mcq_pass' => 8, 'practical_full' => null, 'exam_date' => '2026-06-03']);
    }

    public function test_the_end_time_must_be_after_the_start_time(): void
    {
        $this->repo(fn (MockInterface $m) => $m->shouldNotReceive('updateSubject'));

        $this->assertSame(['end_time'], array_keys($this->errors(['start_time' => '10:00', 'end_time' => '09:00'])));
    }
}
