<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Services\AcademicYearService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface — no database.
 */
class AcademicYearServiceTest extends TestCase
{
    public function test_create_defaults_dates_to_the_calendar_year(): void
    {
        $this->mock(AcademicYearRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('create')->once()->withArgs(function (array $data) {
                return $data['start_date'] === '2027-01-01'
                    && $data['end_date'] === '2027-12-31'
                    && $data['code'] === '2027';
            })->andReturn(new AcademicYear);
        });

        app(AcademicYearService::class)->create(['year' => 2027, 'name' => '2027']);
    }

    public function test_create_rejects_a_start_date_outside_the_year(): void
    {
        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));

        try {
            app(AcademicYearService::class)->create(['year' => 2027, 'name' => '2027', 'start_date' => '2026-12-01', 'end_date' => '2027-12-31']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('start_date', $e->errors());
        }
    }

    public function test_create_rejects_end_before_start(): void
    {
        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));

        try {
            app(AcademicYearService::class)->create([
                'year' => 2027, 'name' => '2027', 'start_date' => '2027-06-01', 'end_date' => '2027-01-01',
            ]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('end_date', $e->errors());
        }
    }

    public function test_update_rejects_end_date_before_the_saved_start_date(): void
    {
        $year = AcademicYear::factory()->make(['year' => 2028, 'start_date' => '2028-03-01', 'end_date' => '2028-12-31']);
        $year->id = 5;
        $year->exists = true;

        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('update'));

        try {
            app(AcademicYearService::class)->update($year, ['end_date' => '2028-02-01']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('end_date', $e->errors());
        }
    }

    public function test_update_unrelated_field_does_not_recheck_dates(): void
    {
        $year = AcademicYear::factory()->make(['year' => 2028, 'start_date' => '2028-01-01', 'end_date' => '2028-12-31']);
        $year->id = 6;
        $year->exists = true;

        $this->mock(AcademicYearRepositoryInterface::class, function (MockInterface $mock) use ($year) {
            $mock->shouldReceive('update')->once()->with($year, ['name' => 'Renamed'])->andReturn($year);
        });

        app(AcademicYearService::class)->update($year, ['name' => 'Renamed']);
    }

    public function test_activate_deactivates_other_years_before_activating(): void
    {
        $year = AcademicYear::factory()->make(['year' => 2027]);
        $year->id = 7;
        $year->exists = true;

        $this->mock(AcademicYearRepositoryInterface::class, function (MockInterface $mock) use ($year) {
            $mock->shouldReceive('deactivateAllExcept')->once()->with($year)->globally()->ordered();
            $mock->shouldReceive('update')->once()->with($year, ['is_active' => true])->andReturn($year)->globally()->ordered();
        });

        app(AcademicYearService::class)->activate($year);
    }

    public function test_delete_is_refused_when_year_is_active(): void
    {
        $year = new AcademicYear(['is_active' => true]);

        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $mock) => $mock->shouldNotReceive('delete'));

        $this->assertConflict(fn () => app(AcademicYearService::class)->delete($year));
    }

    public function test_delete_is_refused_when_year_has_students(): void
    {
        $year = new AcademicYear(['is_active' => false]);

        $this->mock(AcademicYearRepositoryInterface::class, function (MockInterface $mock) use ($year) {
            $mock->shouldReceive('hasStudents')->once()->with($year)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(AcademicYearService::class)->delete($year));
    }

    public function test_delete_is_refused_when_year_has_exams(): void
    {
        $year = new AcademicYear(['is_active' => false]);

        $this->mock(AcademicYearRepositoryInterface::class, function (MockInterface $mock) use ($year) {
            $mock->shouldReceive('hasStudents')->once()->andReturn(false);
            $mock->shouldReceive('hasExams')->once()->with($year)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(AcademicYearService::class)->delete($year));
    }

    public function test_delete_is_refused_when_year_has_fee_structures(): void
    {
        $year = new AcademicYear(['is_active' => false]);

        $this->mock(AcademicYearRepositoryInterface::class, function (MockInterface $mock) use ($year) {
            $mock->shouldReceive('hasStudents')->once()->andReturn(false);
            $mock->shouldReceive('hasExams')->once()->andReturn(false);
            $mock->shouldReceive('hasFeeStructures')->once()->with($year)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(AcademicYearService::class)->delete($year));
    }

    public function test_delete_is_refused_when_year_has_class_teacher_rows(): void
    {
        $year = new AcademicYear(['is_active' => false]);

        $this->mock(AcademicYearRepositoryInterface::class, function (MockInterface $mock) use ($year) {
            $mock->shouldReceive('hasStudents')->once()->andReturn(false);
            $mock->shouldReceive('hasExams')->once()->andReturn(false);
            $mock->shouldReceive('hasFeeStructures')->once()->andReturn(false);
            $mock->shouldReceive('hasClassTeacherRows')->once()->with($year)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(AcademicYearService::class)->delete($year));
    }

    public function test_delete_is_refused_when_year_has_subject_assignments(): void
    {
        $year = new AcademicYear(['is_active' => false]);

        $this->mock(AcademicYearRepositoryInterface::class, function (MockInterface $mock) use ($year) {
            $mock->shouldReceive('hasStudents')->once()->andReturn(false);
            $mock->shouldReceive('hasExams')->once()->andReturn(false);
            $mock->shouldReceive('hasFeeStructures')->once()->andReturn(false);
            $mock->shouldReceive('hasClassTeacherRows')->once()->andReturn(false);
            $mock->shouldReceive('hasSubjectAssignments')->once()->with($year)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(AcademicYearService::class)->delete($year));
    }

    public function test_delete_removes_an_unused_inactive_year(): void
    {
        $year = new AcademicYear(['is_active' => false]);

        $this->mock(AcademicYearRepositoryInterface::class, function (MockInterface $mock) use ($year) {
            $mock->shouldReceive('hasStudents')->once()->andReturn(false);
            $mock->shouldReceive('hasExams')->once()->andReturn(false);
            $mock->shouldReceive('hasFeeStructures')->once()->andReturn(false);
            $mock->shouldReceive('hasClassTeacherRows')->once()->andReturn(false);
            $mock->shouldReceive('hasSubjectAssignments')->once()->andReturn(false);
            $mock->shouldReceive('delete')->once()->with($year);
        });

        app(AcademicYearService::class)->delete($year);
    }

    public function test_create_reports_a_concurrent_duplicate_year_as_a_validation_error(): void
    {
        $this->mock(AcademicYearRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('create')->once()->andThrow(
                new UniqueConstraintViolationException('sqlite', 'insert into academic_years', [], new RuntimeException('UNIQUE constraint failed: academic_years.year'))
            );
        });

        try {
            app(AcademicYearService::class)->create(['year' => 2027, 'name' => '2027']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('year', $e->errors());
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
