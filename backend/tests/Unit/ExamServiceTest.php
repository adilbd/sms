<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\Subject;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\ClassSubjectRepositoryInterface;
use App\Repositories\Contracts\ExamRepositoryInterface;
use App\Services\ExamService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Services are unit-tested against mocked repository interfaces, no database.
 */
class ExamServiceTest extends TestCase
{
    private function year(): AcademicYear
    {
        $year = new AcademicYear(['year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
        $year->id = 3;

        return $year;
    }

    private function klass(int $id): Classes
    {
        $class = new Classes(['number' => $id, 'name' => "Class {$id}"]);
        $class->id = $id;

        return $class;
    }

    private function exam(array $attributes = []): Exam
    {
        $exam = new Exam([
            'academic_year_id' => 3, 'name_en' => 'Half Yearly', 'code' => 'HY', 'type' => 'half_yearly',
            'start_date' => '2026-06-01', 'end_date' => '2026-06-15', ...$attributes,
        ]);
        $exam->id = 8;

        return $exam;
    }

    private function row(int $subjectId, array $attributes = []): ClassSubject
    {
        $row = new ClassSubject([
            'subject_id' => $subjectId, 'group' => null, 'type' => 'compulsory', 'paper_group' => null,
            'written_full' => 50, 'written_pass' => 17, 'mcq_full' => 25, 'mcq_pass' => 8,
            ...$attributes,
        ]);
        $row->setRelation('subject', new Subject(['name' => "Subject {$subjectId}"]));

        return $row;
    }

    /**
     * @param  array<int, list<ClassSubject>>  $curricula  class id => curriculum rows
     */
    private function repos(array $curricula, callable $exams, ?AcademicYear $active = null): void
    {
        $this->mock(AcademicYearRepositoryInterface::class, function (MockInterface $m) use ($active) {
            $m->shouldReceive('findOrFail')->andReturn($this->year())->byDefault();
            $m->shouldReceive('findActive')->andReturn($active)->byDefault();
        });
        $this->mock(ClassSubjectRepositoryInterface::class, function (MockInterface $m) use ($curricula) {
            $m->shouldReceive('lockClass')->andReturnUsing(fn ($class) => $class)->byDefault();
            $m->shouldReceive('forClass')->andReturnUsing(fn (Classes $class) => new Collection($curricula[$class->id] ?? []))->byDefault();
        });
        $this->mock(ExamRepositoryInterface::class, function (MockInterface $m) use ($exams) {
            $m->shouldReceive('classesByIds')->andReturnUsing(fn (array $ids) => new Collection(array_map(fn ($id) => $this->klass($id), $ids)))->byDefault();
            $m->shouldReceive('lockExam')->andReturnUsing(fn ($exam) => $exam)->byDefault();
            $m->shouldReceive('findSubject')->byDefault();
            $m->shouldReceive('loadDetail')->andReturnUsing(fn ($exam) => $exam)->byDefault();
            $exams($m);
        });
    }

    /** @return array<string, list<string>> */
    private function errors(callable $call): array
    {
        try {
            $call();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            return $e->errors();
        }
    }

    private function assertConflict(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected a 409 HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    private function payload(array $extra = []): array
    {
        return [
            'academic_year_id' => 3, 'name_en' => 'Half Yearly', 'code' => 'HY', 'type' => 'half_yearly',
            'start_date' => '2026-06-01', 'end_date' => '2026-06-15', 'class_ids' => [10, 9], ...$extra,
        ];
    }

    public function test_create_locks_the_classes_in_id_order_then_snapshots_each_curriculum(): void
    {
        $created = $this->exam();
        $curricula = [
            9 => [$this->row(1, ['paper_group' => 'bangla']), $this->row(2, ['group' => 'science', 'type' => 'optional'])],
            10 => [$this->row(1)],
        ];

        $this->repos($curricula, function (MockInterface $m) use ($created) {
            $m->shouldReceive('classesByIds')->once()->with([10, 9])->andReturn(new Collection([$this->klass(9), $this->klass(10)]));
            $m->shouldReceive('create')->once()->andReturn($created);
            $m->shouldReceive('replaceClassSubjects')->once()->withArgs(function (Exam $exam, Classes $class, array $rows) {
                return $class->id === 9 && count($rows) === 2
                    && $rows[0]['paper_group'] === 'bangla' && $rows[0]['written_full'] === 50 && $rows[0]['mcq_pass'] === 8
                    && $rows[0]['practical_full'] === null && $rows[0]['sort_order'] === 0
                    && $rows[1]['group'] === 'science' && $rows[1]['type'] === 'optional' && $rows[1]['sort_order'] === 1;
            });
            $m->shouldReceive('replaceClassSubjects')->once()->withArgs(fn (Exam $exam, Classes $class, array $rows) => $class->id === 10 && count($rows) === 1);
        });
        $this->mock(ClassSubjectRepositoryInterface::class, function (MockInterface $m) use ($curricula) {
            $m->shouldReceive('lockClass')->twice()->andReturnUsing(fn ($class) => $class);
            $m->shouldReceive('forClass')->andReturnUsing(fn (Classes $class) => new Collection($curricula[$class->id]));
        });

        app(ExamService::class)->create($this->payload());
    }

    public function test_create_needs_one_of_the_two_names(): void
    {
        $this->repos([], fn ($m) => $m->shouldNotReceive('create'));

        $errors = $this->errors(fn () => app(ExamService::class)->create($this->payload(['name_en' => null, 'name_bn' => ' '])));

        $this->assertSame(['name_en'], array_keys($errors));
    }

    public function test_a_bangla_only_name_is_accepted(): void
    {
        $this->repos([9 => [$this->row(1)]], function (MockInterface $m) {
            $m->shouldReceive('create')->once()->andReturn($this->exam());
            $m->shouldReceive('replaceClassSubjects')->once();
        });

        app(ExamService::class)->create($this->payload(['name_en' => null, 'name_bn' => 'অর্ধবার্ষিক পরীক্ষা', 'class_ids' => [9]]));
    }

    public function test_create_refuses_dates_outside_the_year_and_an_end_before_the_start(): void
    {
        $this->repos([], fn ($m) => $m->shouldNotReceive('create'));

        $outside = $this->errors(fn () => app(ExamService::class)->create($this->payload(['start_date' => '2025-12-30', 'end_date' => '2027-01-02'])));
        $this->assertEqualsCanonicalizing(['start_date', 'end_date'], array_keys($outside));

        $reversed = $this->errors(fn () => app(ExamService::class)->create($this->payload(['start_date' => '2026-06-10', 'end_date' => '2026-06-01'])));
        $this->assertSame(['end_date'], array_keys($reversed));
    }

    public function test_a_class_without_curriculum_or_without_a_marks_part_is_refused_per_class(): void
    {
        $this->repos([
            9 => [],
            10 => [$this->row(1, ['written_full' => null, 'written_pass' => null, 'mcq_full' => null, 'mcq_pass' => null])],
        ], fn ($m) => $m->shouldNotReceive('create'));

        $errors = $this->errors(fn () => app(ExamService::class)->create($this->payload()));

        // class_ids is [10, 9], so 10 is index 0 and 9 is index 1.
        $this->assertEqualsCanonicalizing(['class_ids.0', 'class_ids.1'], array_keys($errors));
    }

    public function test_a_concurrent_duplicate_code_becomes_a_validation_error(): void
    {
        $this->repos([9 => [$this->row(1)]], function (MockInterface $m) {
            $m->shouldReceive('create')->once()->andThrow(new UniqueConstraintViolationException(
                'sqlite', 'insert into exams', [], new RuntimeException('UNIQUE constraint failed: exams.academic_year_id, exams.code')
            ));
            $m->shouldNotReceive('replaceClassSubjects');
        });

        $errors = $this->errors(fn () => app(ExamService::class)->create($this->payload(['class_ids' => [9]])));

        $this->assertSame(['code'], array_keys($errors));
    }

    public function test_an_unrelated_unique_violation_is_not_reported_as_the_code(): void
    {
        $this->repos([9 => [$this->row(1)]], function (MockInterface $m) {
            $m->shouldReceive('create')->once()->andThrow(new UniqueConstraintViolationException(
                'sqlite', 'insert into exams', [], new RuntimeException('UNIQUE constraint failed: exams.id')
            ));
        });

        $this->expectException(UniqueConstraintViolationException::class);

        app(ExamService::class)->create($this->payload(['class_ids' => [9]]));
    }

    public function test_update_checks_a_partial_date_change_against_the_saved_dates(): void
    {
        $exam = $this->exam();

        $this->repos([], function (MockInterface $m) {
            $m->shouldReceive('update')->never();
        });

        // Only end_date is sent: it must still be on or after the saved start (2026-06-01).
        $errors = $this->errors(fn () => app(ExamService::class)->update($exam, ['end_date' => '2026-05-30']));

        $this->assertSame(['end_date'], array_keys($errors));
    }

    public function test_update_refuses_removing_a_class_that_has_marks(): void
    {
        $exam = $this->exam();

        $this->repos([], function (MockInterface $m) {
            $m->shouldReceive('classIds')->andReturn([9, 10]);
            $m->shouldReceive('hasMarksForClass')->with(\Mockery::type(Exam::class), 10)->andReturn(true);
            $m->shouldNotReceive('deleteClassSubjects');
            $m->shouldNotReceive('update');
        });

        $this->assertConflict(fn () => app(ExamService::class)->update($exam, ['class_ids' => [9]]));
    }

    public function test_update_adds_a_new_class_and_removes_one_without_marks(): void
    {
        $exam = $this->exam();

        $this->repos([9 => [$this->row(1)]], function (MockInterface $m) {
            $m->shouldReceive('classIds')->andReturn([10]);
            $m->shouldReceive('hasMarksForClass')->andReturn(false);
            $m->shouldReceive('deleteClassSubjects')->once()->with(\Mockery::type(Exam::class), 10);
            $m->shouldReceive('replaceClassSubjects')->once()->withArgs(fn (Exam $e, Classes $class, array $rows) => $class->id === 9 && count($rows) === 1);
        });

        app(ExamService::class)->update($exam, ['class_ids' => [9]]);
    }

    public function test_delete_is_refused_once_marks_exist(): void
    {
        $this->repos([], function (MockInterface $m) {
            $m->shouldReceive('hasMarks')->once()->andReturn(true);
            $m->shouldNotReceive('deleteSubjects');
            $m->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(ExamService::class)->delete($this->exam()));
    }

    public function test_delete_removes_the_subjects_then_the_exam(): void
    {
        $this->repos([], function (MockInterface $m) {
            $m->shouldReceive('hasMarks')->once()->andReturn(false);
            $m->shouldReceive('deleteSubjects')->once()->ordered();
            $m->shouldReceive('delete')->once()->ordered();
        });

        app(ExamService::class)->delete($this->exam());
    }

    public function test_marks_entry_opens_only_from_draft(): void
    {
        $draft = $this->exam(['status' => Exam::STATUS_DRAFT]);

        $this->repos([], function (MockInterface $m) use ($draft) {
            $m->shouldReceive('update')->once()->with($draft, ['status' => Exam::STATUS_MARKS_ENTRY])->andReturn($draft);
        });
        app(ExamService::class)->openMarksEntry($draft);

        foreach ([Exam::STATUS_MARKS_ENTRY, Exam::STATUS_PROCESSED, Exam::STATUS_PUBLISHED] as $status) {
            $this->repos([], fn (MockInterface $m) => $m->shouldNotReceive('update'));

            $this->assertConflict(fn () => app(ExamService::class)->openMarksEntry($this->exam(['status' => $status])));
        }
    }

    public function test_regenerate_is_refused_once_marks_exist_and_404s_for_a_class_not_in_the_exam(): void
    {
        $this->repos([9 => [$this->row(1)]], function (MockInterface $m) {
            $m->shouldReceive('classIds')->andReturn([9]);
            $m->shouldReceive('hasMarksForClass')->once()->andReturn(true);
            $m->shouldNotReceive('replaceClassSubjects');
        });

        $this->assertConflict(fn () => app(ExamService::class)->regenerateClass($this->exam(), $this->klass(9)));

        $this->repos([10 => [$this->row(1)]], function (MockInterface $m) {
            $m->shouldReceive('classIds')->andReturn([9]);
            $m->shouldNotReceive('replaceClassSubjects');
        });

        try {
            app(ExamService::class)->regenerateClass($this->exam(), $this->klass(10));
            $this->fail('Expected a 404 HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_regenerate_replaces_the_class_schedule_from_the_curriculum(): void
    {
        $this->repos([9 => [$this->row(1), $this->row(2)]], function (MockInterface $m) {
            $m->shouldReceive('classIds')->andReturn([9]);
            $m->shouldReceive('hasMarksForClass')->once()->andReturn(false);
            $m->shouldReceive('replaceClassSubjects')->once()->withArgs(fn (Exam $e, Classes $class, array $rows) => count($rows) === 2);
        });

        app(ExamService::class)->regenerateClass($this->exam(), $this->klass(9));
    }

    public function test_list_defaults_to_the_active_year_and_keeps_an_explicit_one(): void
    {
        $this->repos([], function (MockInterface $m) {
            $m->shouldReceive('paginate')->once()->with(['type' => 'annual', 'academic_year_id' => 3], 15)->andReturn(new LengthAwarePaginator([], 0, 15));
            $m->shouldReceive('paginate')->once()->with(['academic_year_id' => 5], 15)->andReturn(new LengthAwarePaginator([], 0, 15));
        }, $this->year());

        app(ExamService::class)->list(['type' => 'annual'], 15);
        app(ExamService::class)->list(['academic_year_id' => 5], 15);
    }
}
