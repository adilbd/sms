<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\Section;
use App\Models\StudentEnrolment;
use App\Models\Subject;
use App\Models\User;
use App\Repositories\Contracts\ExamMarkRepositoryInterface;
use App\Repositories\Contracts\ExamRepositoryInterface;
use App\Repositories\Contracts\SectionRepositoryInterface;
use App\Services\ExamMarkService;
use App\Services\SubjectAssignmentService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Services are unit-tested against mocked repository interfaces, no database.
 */
class ExamMarkServiceTest extends TestCase
{
    private function exam(string $status = Exam::STATUS_MARKS_ENTRY): Exam
    {
        $exam = new Exam(['academic_year_id' => 3, 'status' => $status]);
        $exam->id = 8;
        $exam->setRelation('academicYear', tap(new AcademicYear, fn ($y) => $y->id = 3));

        return $exam;
    }

    /** Written 50, MCQ 25, no practical part. */
    private function examSubject(array $attributes = []): ExamSubject
    {
        $subject = new ExamSubject([
            'exam_id' => 8, 'class_id' => 9, 'subject_id' => 11, 'written_full' => 50, 'written_pass' => 17,
            'mcq_full' => 25, 'mcq_pass' => 8, ...$attributes,
        ]);
        $subject->id = 40;
        $subject->setRelation('subject', tap(new Subject, fn ($s) => $s->id = 11));

        return $subject;
    }

    private function section(int $classId = 9): Section
    {
        $section = new Section(['class_id' => $classId]);
        $section->id = 20;

        return $section;
    }

    private function enrolment(int $id, int $studentId): StudentEnrolment
    {
        $enrolment = new StudentEnrolment(['student_id' => $studentId]);
        $enrolment->id = $id;

        return $enrolment;
    }

    private function user(): User
    {
        $user = new User;
        $user->id = 5;

        return $user;
    }

    private function repos(callable $marks, bool $canEnter = true, ?ExamSubject $subject = null, ?Section $section = null, ?callable $exams = null): void
    {
        $subject ??= $this->examSubject();
        $section ??= $this->section();

        $this->mock(ExamRepositoryInterface::class, function (MockInterface $m) use ($subject, $exams) {
            $m->shouldReceive('findSubject')->andReturn($subject)->byDefault();
            $m->shouldReceive('lockExam')->andReturnUsing(fn ($exam) => $exam)->byDefault();
            $m->shouldReceive('lockSubject')->andReturn($subject)->byDefault();
            $exams && $exams($m);
        });
        $this->mock(SectionRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findOrFail')->andReturn($section)->byDefault());
        $this->mock(SubjectAssignmentService::class, fn (MockInterface $m) => $m->shouldReceive('canEnterMarks')->andReturn($canEnter)->byDefault());
        $this->mock(ExamMarkRepositoryInterface::class, function (MockInterface $m) use ($section, $marks) {
            $m->shouldReceive('lockSection')->andReturn($section)->byDefault();
            $m->shouldReceive('sheetEnrolments')->andReturn(new Collection([$this->enrolment(100, 1), $this->enrolment(101, 2)]))->byDefault();
            $marks($m);
        });
    }

    private function data(array $marks): array
    {
        return ['section_id' => 20, 'exam_subject_id' => 40, 'marks' => $marks];
    }

    /** @return array<string, list<string>> */
    private function errors(array $marks, ?ExamSubject $subject = null): array
    {
        try {
            app(ExamMarkService::class)->save($this->user(), $this->exam(), $this->data($marks));
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

    public function test_save_locks_section_then_exam_then_subject_and_upserts_the_sheet(): void
    {
        $this->repos(function (MockInterface $m) {
            $m->shouldReceive('lockSection')->once()->with(20)->ordered()->andReturn($this->section());
            $m->shouldReceive('sheetEnrolments')->twice()->andReturn(new Collection([$this->enrolment(100, 1), $this->enrolment(101, 2)]));
            $m->shouldReceive('saveRows')->once()->ordered()->with(\Mockery::type(ExamSubject::class), [
                ['student_id' => 1, 'enrolment_id' => 100, 'written' => 40, 'mcq' => 20.5, 'practical' => null, 'is_absent' => false],
                ['student_id' => 2, 'enrolment_id' => 101, 'written' => null, 'mcq' => null, 'practical' => null, 'is_absent' => true],
            ], 5);
        }, exams: function (MockInterface $m) {
            $m->shouldReceive('lockExam')->once()->ordered()->andReturnUsing(fn ($exam) => $exam);
            $m->shouldReceive('lockSubject')->once()->ordered()->andReturn($this->examSubject());
        });

        app(ExamMarkService::class)->save($this->user(), $this->exam(), $this->data([
            ['student_id' => 1, 'written' => 40, 'mcq' => 20.5, 'practical' => '', 'is_absent' => false],
            ['student_id' => 2, 'is_absent' => true],
        ]));
    }

    public function test_a_user_who_may_not_enter_marks_gets_403_before_any_lock(): void
    {
        $this->repos(function (MockInterface $m) {
            $m->shouldNotReceive('lockSection');
            $m->shouldNotReceive('saveRows');
        }, canEnter: false);

        $this->assertStatus(403, fn () => app(ExamMarkService::class)->save($this->user(), $this->exam(), $this->data([['student_id' => 1, 'written' => 1]])));
        $this->assertStatus(403, fn () => app(ExamMarkService::class)->sheet($this->user(), $this->exam(), 20, 40));
    }

    public function test_the_assignment_is_checked_again_once_the_locks_are_held(): void
    {
        // Still assigned when the sheet is resolved, removed before the save gets its locks.
        $this->repos(function (MockInterface $m) {
            $m->shouldReceive('lockSection')->once()->andReturn($this->section());
            $m->shouldNotReceive('saveRows');
        });
        $this->mock(SubjectAssignmentService::class, function (MockInterface $m) {
            $m->shouldReceive('canEnterMarks')->twice()->andReturn(true, false);
        });

        $this->assertStatus(403, fn () => app(ExamMarkService::class)->save($this->user(), $this->exam(), $this->data([['student_id' => 1, 'written' => 1]])));
    }

    public function test_saving_into_a_processed_exam_sends_it_back_to_mark_entry(): void
    {
        $locked = $this->exam(Exam::STATUS_PROCESSED);

        $this->repos(function (MockInterface $m) {
            $m->shouldReceive('saveRows')->once();
        }, exams: function (MockInterface $m) use ($locked) {
            $m->shouldReceive('lockExam')->once()->andReturn($locked);
            $m->shouldReceive('update')->once()->with($locked, ['status' => Exam::STATUS_MARKS_ENTRY])->andReturn($locked);
        });

        $result = app(ExamMarkService::class)->save($this->user(), $this->exam(Exam::STATUS_PROCESSED), $this->data([['student_id' => 1, 'written' => 1]]));

        $this->assertSame(Exam::STATUS_MARKS_ENTRY, $result['exam']->status);
    }

    public function test_saving_into_an_exam_in_mark_entry_leaves_its_status_alone(): void
    {
        $this->repos(function (MockInterface $m) {
            $m->shouldReceive('saveRows')->once();
        }, exams: fn (MockInterface $m) => $m->shouldNotReceive('update'));

        app(ExamMarkService::class)->save($this->user(), $this->exam(), $this->data([['student_id' => 1, 'written' => 1]]));
    }

    public function test_only_an_exam_in_mark_entry_or_processed_accepts_marks(): void
    {
        foreach ([Exam::STATUS_DRAFT, Exam::STATUS_PUBLISHED] as $status) {
            $this->repos(fn (MockInterface $m) => $m->shouldNotReceive('saveRows'));
            $locked = $this->exam($status);
            $this->mock(ExamRepositoryInterface::class, function (MockInterface $m) use ($locked) {
                $m->shouldReceive('findSubject')->andReturn($this->examSubject());
                $m->shouldReceive('lockExam')->andReturn($locked);
            });

            $this->assertStatus(409, fn () => app(ExamMarkService::class)->save($this->user(), $this->exam(), $this->data([['student_id' => 1, 'written' => 1]])));
        }
    }

    public function test_a_section_from_another_class_is_refused(): void
    {
        $this->repos(fn (MockInterface $m) => $m->shouldNotReceive('lockSection'), section: $this->section(classId: 10));

        try {
            app(ExamMarkService::class)->save($this->user(), $this->exam(), $this->data([['student_id' => 1, 'written' => 1]]));
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['section_id'], array_keys($e->errors()));
        }
    }

    public function test_an_exam_subject_of_another_exam_is_refused(): void
    {
        $this->repos(fn (MockInterface $m) => $m->shouldNotReceive('lockSection'), exams: fn (MockInterface $m) => $m->shouldReceive('findSubject')->andReturn(null));

        try {
            app(ExamMarkService::class)->save($this->user(), $this->exam(), $this->data([['student_id' => 1, 'written' => 1]]));
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['exam_subject_id'], array_keys($e->errors()));
        }
    }

    public function test_a_student_who_is_not_on_the_sheet_is_refused_per_row(): void
    {
        $this->repos(fn (MockInterface $m) => $m->shouldNotReceive('saveRows'));

        $errors = $this->errors([['student_id' => 1, 'written' => 10], ['student_id' => 99, 'written' => 10]]);

        $this->assertSame(['marks.1.student_id'], array_keys($errors));
    }

    public function test_a_part_above_its_full_mark_and_a_part_the_subject_lacks_are_refused_per_row(): void
    {
        $this->repos(fn (MockInterface $m) => $m->shouldNotReceive('saveRows'));

        $errors = $this->errors([
            ['student_id' => 1, 'written' => 50, 'mcq' => 25],
            ['student_id' => 2, 'written' => 50.01, 'mcq' => 26, 'practical' => 5],
        ]);

        $this->assertEqualsCanonicalizing(['marks.1.written', 'marks.1.mcq', 'marks.1.practical'], array_keys($errors));
    }

    public function test_an_absent_student_with_any_part_is_refused(): void
    {
        $this->repos(fn (MockInterface $m) => $m->shouldNotReceive('saveRows'));

        $errors = $this->errors([['student_id' => 1, 'written' => 0, 'is_absent' => true]]);

        $this->assertSame(['marks.0.is_absent'], array_keys($errors));
    }

    public function test_zero_is_a_valid_mark(): void
    {
        $this->repos(function (MockInterface $m) {
            $m->shouldReceive('saveRows')->once()->withArgs(fn ($subject, array $rows) => $rows[0]['written'] === 0);
            $m->shouldReceive('sheetEnrolments')->andReturn(new Collection([$this->enrolment(100, 1)]));
        });

        app(ExamMarkService::class)->save($this->user(), $this->exam(), $this->data([['student_id' => 1, 'written' => 0]]));
    }
}
