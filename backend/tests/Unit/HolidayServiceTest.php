<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Holiday;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\HolidayRepositoryInterface;
use App\Services\HolidayService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Services are unit-tested against mocked repository interfaces, no database.
 */
class HolidayServiceTest extends TestCase
{
    private function year(string $start = '2026-01-01'): AcademicYear
    {
        $year = new AcademicYear(['year' => 2026, 'start_date' => $start, 'end_date' => '2026-12-31']);
        $year->id = 3;

        return $year;
    }

    private function years(?AcademicYear $year): void
    {
        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findByYear')->with(2026)->andReturn($year));
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

    public function test_create_derives_the_academic_year_from_the_date(): void
    {
        $this->years($this->year());
        $this->mock(HolidayRepositoryInterface::class, function (MockInterface $m) {
            $m->shouldReceive('create')->once()
                ->with(['date' => '2026-02-21', 'name_en' => 'Shaheed Dibosh', 'academic_year_id' => 3])
                ->andReturn(new Holiday);
        });

        app(HolidayService::class)->create(['date' => '2026-02-21', 'name_en' => 'Shaheed Dibosh']);
    }

    public function test_create_needs_a_name(): void
    {
        $this->mock(HolidayRepositoryInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('create'));

        $errors = $this->errors(fn () => app(HolidayService::class)->create(['date' => '2026-02-21', 'name_en' => '', 'name_bn' => null]));

        $this->assertArrayHasKey('name_en', $errors);
    }

    public function test_create_refuses_a_date_outside_any_academic_year(): void
    {
        $this->years(null);
        $this->mock(HolidayRepositoryInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('create'));

        $this->assertArrayHasKey('date', $this->errors(fn () => app(HolidayService::class)->create(['date' => '2026-02-21', 'name_en' => 'X'])));

        $this->years($this->year('2026-03-01'));
        $this->assertArrayHasKey('date', $this->errors(fn () => app(HolidayService::class)->create(['date' => '2026-02-21', 'name_en' => 'X'])));
    }

    public function test_update_checks_the_name_rule_against_the_holiday_with_the_input_applied(): void
    {
        $holiday = new Holiday(['date' => '2026-02-21', 'name_en' => 'Only name', 'name_bn' => null]);
        $this->mock(HolidayRepositoryInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('update'));

        $errors = $this->errors(fn () => app(HolidayService::class)->update($holiday, ['name_en' => null]));

        $this->assertArrayHasKey('name_en', $errors);
    }

    public function test_update_only_rederives_the_year_when_the_date_changes(): void
    {
        $holiday = new Holiday(['date' => '2026-02-21', 'name_en' => 'Old']);
        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('findByYear'));
        $this->mock(HolidayRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('update')->once()->with($holiday, ['name_en' => 'New'])->andReturn($holiday));

        app(HolidayService::class)->update($holiday, ['name_en' => 'New']);

        $this->years($this->year());
        $this->mock(HolidayRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('update')->once()->with($holiday, ['date' => '2026-03-26', 'academic_year_id' => 3])->andReturn($holiday));

        app(HolidayService::class)->update($holiday, ['date' => '2026-03-26']);
    }

    public function test_a_concurrent_duplicate_date_becomes_a_validation_error(): void
    {
        $this->years($this->year());
        $this->mock(HolidayRepositoryInterface::class, function (MockInterface $m) {
            $previous = new \PDOException('SQLSTATE[23000]: UNIQUE constraint failed: holidays.date');
            $m->shouldReceive('create')->andThrow(new UniqueConstraintViolationException('sqlite', 'insert', [], $previous));
        });

        $errors = $this->errors(fn () => app(HolidayService::class)->create(['date' => '2026-02-21', 'name_en' => 'X']));

        $this->assertArrayHasKey('date', $errors);
    }

    public function test_delete_and_list_go_through_the_repository(): void
    {
        $holiday = new Holiday;
        $this->mock(AcademicYearRepositoryInterface::class);
        $this->mock(HolidayRepositoryInterface::class, function (MockInterface $m) use ($holiday) {
            $m->shouldReceive('delete')->once()->with($holiday);
            $m->shouldReceive('paginate')->once()->with(['academic_year_id' => 3], 15);
        });

        app(HolidayService::class)->delete($holiday);
        app(HolidayService::class)->list(['academic_year_id' => 3], 15);
    }
}
