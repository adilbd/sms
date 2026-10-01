<?php

namespace Tests\Unit;

use App\Models\Classes;
use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\ExamSubject;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\Subject;
use App\Models\User;
use App\Repositories\Contracts\ExamRepositoryInterface;
use App\Repositories\Contracts\ExamResultRepositoryInterface;
use App\Services\ResultService;
use App\Services\StudentService;
use Illuminate\Database\Eloquent\Collection;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Services are unit-tested against mocked repository interfaces, no database. The grading
 * rules themselves are in GpaTest; these cover the transitions and the orchestration.
 */
class ResultServiceTest extends TestCase
{
    private function exam(string $status = Exam::STATUS_MARKS_ENTRY): Exam
    {
        $exam = new Exam(['academic_year_id' => 3, 'status' => $status]);
        $exam->id = 8;

        return $exam;
    }

    private function examSubject(int $id, int $subjectId, array $attributes = []): ExamSubject
    {
        $subject = new ExamSubject([
            'exam_id' => 8, 'class_id' => 9, 'subject_id' => $subjectId, 'type' => 'compulsory',
            'written_full' => 100, 'written_pass' => 33, ...$attributes,
        ]);
        $subject->id = $id;
        $subject->setRelation('subject', new Subject(['name' => "Subject {$subjectId}", 'name_bn' => null]));

        return $subject;
    }

    private function enrolment(int $id, int $studentId, int $sectionId = 20): StudentEnrolment
    {
        $enrolment = new StudentEnrolment(['student_id' => $studentId, 'class_id' => 9, 'section_id' => $sectionId]);
        $enrolment->id = $id;
        $class = new Classes(['name' => 'Class 9']);
        $class->id = 9;
        $section = new Section(['name' => 'A']);
        $section->id = $sectionId;
        $enrolment->setRelation('class', $class);
        $enrolment->setRelation('section', $section);

        return $enrolment;
    }

    private function mark(int $examSubjectId, int $studentId, float $written): ExamMark
    {
        return new ExamMark(['exam_subject_id' => $examSubjectId, 'student_id' => $studentId, 'written' => $written, 'is_absent' => false]);
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

    /**
     * @param  callable(MockInterface): void  $exams
     * @param  callable(MockInterface): void  $results
     */
    private function repos(callable $exams, ?callable $results = null, ?callable $students = null): void
    {
        $this->mock(ExamRepositoryInterface::class, function (MockInterface $m) use ($exams) {
            $m->shouldReceive('loadDetail')->andReturnUsing(fn ($exam) => $exam)->byDefault();
            $exams($m);
        });
        $this->mock(ExamResultRepositoryInterface::class, function (MockInterface $m) use ($results) {
            $results && $results($m);
        });
        $this->mock(StudentService::class, function (MockInterface $m) use ($students) {
            $students && $students($m);
        });
    }

    // Transitions

    public function test_a_draft_or_published_exam_cannot_be_processed(): void
    {
        foreach ([Exam::STATUS_DRAFT, Exam::STATUS_PUBLISHED] as $status) {
            $locked = $this->exam($status);
            $this->repos(function (MockInterface $m) use ($locked) {
                $m->shouldReceive('lockExam')->once()->andReturn($locked);
                $m->shouldNotReceive('update');
            }, function (MockInterface $m) {
                $m->shouldNotReceive('replaceForExam');
            });

            $this->assertStatus(409, fn () => app(ResultService::class)->process($this->exam($status)));
        }
    }

    public function test_processing_computes_every_enrolment_replaces_the_results_and_marks_the_exam_processed(): void
    {
        $locked = $this->exam();
        $maths = $this->examSubject(40, 11);
        $science = $this->examSubject(41, 12);
        $rows = null;

        $this->repos(function (MockInterface $m) use ($locked, $maths, $science) {
            $m->shouldReceive('lockExam')->once()->ordered()->andReturn($locked);
            $m->shouldReceive('classIds')->once()->with($locked)->andReturn([9]);
            $m->shouldReceive('subjectsFor')->once()->with($locked, 9)->andReturn(new Collection([$maths, $science]));
            $m->shouldReceive('update')->once()->ordered()->with($locked, ['status' => Exam::STATUS_PROCESSED])
                ->andReturnUsing(function (Exam $exam, array $attributes) {
                    return $exam->fill($attributes);
                });
        }, function (MockInterface $m) use ($locked, $maths, $science, &$rows) {
            // Student 1 (enrolment 100) has 90 and 50; student 2 (101) has 60 and nothing in
            // science; student 3 (102) takes only maths and has 90.
            $m->shouldReceive('marksFor')->once()->with($locked)->andReturn(new Collection([
                $this->mark(40, 1, 90), $this->mark(41, 1, 50), $this->mark(40, 2, 60), $this->mark(40, 3, 90),
            ]));
            $m->shouldReceive('subjectTakers')->with($locked, $maths)->andReturn([100, 101, 102]);
            $m->shouldReceive('subjectTakers')->with($locked, $science)->andReturn([100, 101]);
            $m->shouldReceive('activeEnrolments')->once()->with($locked, 9)->andReturn(new Collection([
                $this->enrolment(100, 1), $this->enrolment(101, 2), $this->enrolment(102, 3),
            ]));
            $m->shouldReceive('replaceForExam')->once()->ordered()->with($locked, \Mockery::on(function (array $given) use (&$rows) {
                $rows = collect($given)->keyBy('enrolment_id');

                return true;
            }));
        });

        $outcome = app(ResultService::class)->process($this->exam());

        // 90 -> A+ (5.00), 50 -> B (3.00): GPA 4.00, total 140.
        $this->assertSame(['4.00', 'A', true, 0, '140.00', '200.00'], [
            $rows[100]['gpa'], $rows[100]['grade'], $rows[100]['is_pass'], $rows[100]['failed_count'],
            $rows[100]['total_obtained'], $rows[100]['total_full'],
        ]);
        $this->assertSame([2, 1, 1], [$rows[100]['passed_count'], $rows[101]['passed_count'], $rows[102]['passed_count']]);
        // 60 is an A- but science is missing, so it is an F: the student fails.
        $this->assertSame(['0.00', 'F', false, 1], [$rows[101]['gpa'], $rows[101]['grade'], $rows[101]['is_pass'], $rows[101]['failed_count']]);
        // Student 3 takes only maths: GPA 5.00.
        $this->assertSame('5.00', $rows[102]['gpa']);

        // Merit: student 3 (5.00), student 1 (4.00), then the failed student 2.
        $this->assertSame([1, 2, 3], array_map(fn ($id) => $rows[$id]['class_position'], [102, 100, 101]));
        $this->assertSame([1, 2, 3], array_map(fn ($id) => $rows[$id]['section_position'], [102, 100, 101]));

        // Student 2 has no science mark at all, so that paper is stored as missing.
        $this->assertTrue($rows[101]['subjects'][1]['papers'][0]['is_missing']);
        $this->assertSame(
            [['class_id' => 9, 'class_name' => 'Class 9', 'section_id' => 20, 'section_name' => 'A', 'students' => 3, 'passed' => 2, 'failed' => 1, 'missing_marks' => 1]],
            $outcome['summary']
        );
        $this->assertSame(Exam::STATUS_PROCESSED, $outcome['exam']->status);
    }

    public function test_processing_ranks_failed_students_by_subjects_passed_before_total_in_class_and_section(): void
    {
        $locked = $this->exam();
        $subjects = [$this->examSubject(40, 11), $this->examSubject(41, 12), $this->examSubject(42, 13)];
        $rows = null;

        $this->repos(function (MockInterface $m) use ($locked, $subjects) {
            $m->shouldReceive('lockExam')->once()->andReturn($locked);
            $m->shouldReceive('classIds')->once()->andReturn([9]);
            $m->shouldReceive('subjectsFor')->once()->andReturn(new Collection($subjects));
            $m->shouldReceive('update')->once()->andReturnUsing(fn (Exam $exam, array $attributes) => $exam->fill($attributes));
        }, function (MockInterface $m) use (&$rows) {
            // Student 1 passes two subjects and fails one (total 90); student 2 passes one and
            // fails two but has the higher total (120); student 3 passes all three.
            $m->shouldReceive('marksFor')->once()->andReturn(new Collection([
                $this->mark(40, 1, 40), $this->mark(41, 1, 40), $this->mark(42, 1, 10),
                $this->mark(40, 2, 100), $this->mark(41, 2, 10), $this->mark(42, 2, 10),
                $this->mark(40, 3, 40), $this->mark(41, 3, 40), $this->mark(42, 3, 40),
            ]));
            $m->shouldReceive('subjectTakers')->andReturn([100, 101, 102]);
            $m->shouldReceive('activeEnrolments')->once()->andReturn(new Collection([
                $this->enrolment(100, 1), $this->enrolment(101, 2), $this->enrolment(102, 3),
            ]));
            $m->shouldReceive('replaceForExam')->once()->with(\Mockery::any(), \Mockery::on(function (array $given) use (&$rows) {
                $rows = collect($given)->keyBy('enrolment_id');

                return true;
            }));
        });

        app(ResultService::class)->process($this->exam());

        $this->assertSame([2, 1, 3], [$rows[100]['passed_count'], $rows[101]['passed_count'], $rows[102]['passed_count']]);
        $this->assertSame([2, 3, 1], array_map(fn ($id) => $rows[$id]['class_position'], [100, 101, 102]));
        $this->assertSame([2, 3, 1], array_map(fn ($id) => $rows[$id]['section_position'], [100, 101, 102]));
    }

    public function test_a_processed_exam_can_be_processed_again(): void
    {
        $locked = $this->exam(Exam::STATUS_PROCESSED);

        $this->repos(function (MockInterface $m) use ($locked) {
            $m->shouldReceive('lockExam')->andReturn($locked);
            $m->shouldReceive('classIds')->andReturn([]);
            $m->shouldReceive('update')->once()->andReturn($locked);
        }, function (MockInterface $m) {
            $m->shouldReceive('marksFor')->andReturn(new Collection);
            $m->shouldReceive('replaceForExam')->once()->with(\Mockery::type(Exam::class), []);
        });

        $this->assertSame([], app(ResultService::class)->process($this->exam(Exam::STATUS_PROCESSED))['summary']);
    }

    public function test_publish_needs_a_processed_exam_and_sets_the_publish_time(): void
    {
        foreach ([Exam::STATUS_DRAFT, Exam::STATUS_MARKS_ENTRY, Exam::STATUS_PUBLISHED] as $status) {
            $locked = $this->exam($status);
            $this->repos(function (MockInterface $m) use ($locked) {
                $m->shouldReceive('lockExam')->andReturn($locked);
                $m->shouldNotReceive('update');
            });

            $this->assertStatus(409, fn () => app(ResultService::class)->publish($this->exam($status)));
        }

        $processed = $this->exam(Exam::STATUS_PROCESSED);
        $this->repos(function (MockInterface $m) use ($processed) {
            $m->shouldReceive('lockExam')->andReturn($processed);
            $m->shouldReceive('update')->once()->withArgs(fn (Exam $exam, array $attributes) => $attributes['status'] === Exam::STATUS_PUBLISHED && $attributes['published_at'] !== null)
                ->andReturn($processed);
        });

        app(ResultService::class)->publish($this->exam(Exam::STATUS_PROCESSED));
    }

    public function test_unpublish_needs_a_published_exam_and_clears_the_publish_time(): void
    {
        foreach ([Exam::STATUS_DRAFT, Exam::STATUS_MARKS_ENTRY, Exam::STATUS_PROCESSED] as $status) {
            $locked = $this->exam($status);
            $this->repos(function (MockInterface $m) use ($locked) {
                $m->shouldReceive('lockExam')->andReturn($locked);
                $m->shouldNotReceive('update');
            });

            $this->assertStatus(409, fn () => app(ResultService::class)->unpublish($this->exam($status)));
        }

        $published = $this->exam(Exam::STATUS_PUBLISHED);
        $this->repos(function (MockInterface $m) use ($published) {
            $m->shouldReceive('lockExam')->andReturn($published);
            $m->shouldReceive('update')->once()->with($published, ['status' => Exam::STATUS_PROCESSED, 'published_at' => null])->andReturn($published);
        });

        app(ResultService::class)->unpublish($this->exam(Exam::STATUS_PUBLISHED));
    }

    public function test_reopen_clears_the_results_under_the_lock_then_sets_marks_entry(): void
    {
        $processed = $this->exam(Exam::STATUS_PROCESSED);
        $order = [];

        $this->repos(function (MockInterface $m) use ($processed, &$order) {
            $m->shouldReceive('lockExam')->once()->andReturnUsing(function () use ($processed, &$order) {
                $order[] = 'lock';

                return $processed;
            });
            $m->shouldReceive('update')->once()->with($processed, ['status' => Exam::STATUS_MARKS_ENTRY])->andReturnUsing(function () use ($processed, &$order) {
                $order[] = 'update';

                return $processed;
            });
        }, function (MockInterface $m) use (&$order) {
            $m->shouldReceive('deleteForExam')->once()->andReturnUsing(function () use (&$order) {
                $order[] = 'delete';
            });
        });

        app(ResultService::class)->reopen($this->exam(Exam::STATUS_PROCESSED));

        $this->assertSame(['lock', 'delete', 'update'], $order);
    }

    public function test_reopen_is_refused_unless_processed(): void
    {
        foreach ([Exam::STATUS_DRAFT, Exam::STATUS_MARKS_ENTRY, Exam::STATUS_PUBLISHED] as $status) {
            $locked = $this->exam($status);
            $this->repos(function (MockInterface $m) use ($locked) {
                $m->shouldReceive('lockExam')->once()->andReturn($locked);
                $m->shouldNotReceive('update');
            }, function (MockInterface $m) {
                $m->shouldNotReceive('deleteForExam');
            });

            $this->assertStatus(409, fn () => app(ResultService::class)->reopen($this->exam($status)));
        }
    }

    // Reads

    public function test_a_breakdown_without_a_result_is_a_404(): void
    {
        $this->repos(fn () => null, fn (MockInterface $m) => $m->shouldReceive('findForStudent')->once()->andReturn(null));

        $this->assertStatus(404, fn () => app(ResultService::class)->breakdown($this->exam(), 5));
    }

    public function test_own_results_are_those_of_the_students_own_record(): void
    {
        $user = new User;
        $student = new Student;
        $student->id = 31;
        $results = new Collection([new ExamResult]);

        $this->repos(fn () => null, function (MockInterface $m) use ($results) {
            $m->shouldReceive('publishedForStudent')->once()->with(31)->andReturn($results);
        }, function (MockInterface $m) use ($student, $user) {
            $m->shouldReceive('findOwn')->once()->with($user)->andReturn($student);
        });

        $this->assertSame($results, app(ResultService::class)->ownResults($user));
    }

    public function test_a_guardian_cannot_read_a_students_results_who_is_not_their_child(): void
    {
        $this->repos(fn () => null, function (MockInterface $m) {
            $m->shouldNotReceive('publishedForStudent');
        }, function (MockInterface $m) {
            $m->shouldReceive('findChildOf')->once()->with(\Mockery::type(User::class), 77)->andReturnUsing(fn () => abort(403));
        });

        $this->assertStatus(403, fn () => app(ResultService::class)->childResults(new User, 77));
    }
}
