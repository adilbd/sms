<?php

namespace Tests\Unit;

use App\Models\Period;
use App\Repositories\Contracts\PeriodRepositoryInterface;
use App\Repositories\Contracts\RoutineRepositoryInterface;
use App\Repositories\Contracts\ShiftRepositoryInterface;
use App\Services\PeriodService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Services are unit-tested against mocked repository interfaces, no database.
 */
class PeriodServiceTest extends TestCase
{
    private function period(array $attributes = []): Period
    {
        $period = new Period(['shift_id' => 4, 'number' => 1, 'start_time' => '08:00:00', 'end_time' => '08:45:00', ...$attributes]);
        $period->id = 10;

        return $period;
    }

    private function mocks(?callable $periods = null, ?callable $routines = null): void
    {
        $this->mock(ShiftRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('lockForUpdate')->with([4])->byDefault());
        $this->mock(PeriodRepositoryInterface::class, function (MockInterface $m) use ($periods) {
            $m->shouldReceive('findOverlapping')->andReturn(null)->byDefault();
            $m->shouldReceive('isUsedInRoutine')->andReturn(false)->byDefault();
            ($periods ?? fn () => null)($m);
        });
        $this->mock(RoutineRepositoryInterface::class, fn (MockInterface $m) => ($routines ?? fn () => null)($m));
    }

    /** @return array<string, list<string>> */
    private function errors(callable $call): array
    {
        try {
            $call();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('Expected a ValidationException.');
    }

    public function test_create_stores_times_with_seconds_after_locking_the_shift(): void
    {
        $created = $this->period();
        $this->mocks(function (MockInterface $m) use ($created) {
            $m->shouldReceive('create')->once()
                ->with(['shift_id' => 4, 'number' => 1, 'start_time' => '08:00:00', 'end_time' => '08:45:00'])
                ->andReturn($created);
        });

        $this->assertSame($created, app(PeriodService::class)->create(['shift_id' => 4, 'number' => 1, 'start_time' => '08:00', 'end_time' => '08:45']));
    }

    public function test_create_refuses_an_overlap_naming_the_other_period(): void
    {
        $this->mocks(function (MockInterface $m) {
            $m->shouldReceive('findOverlapping')->once()->with(4, '08:30:00', '09:15:00', null)->andReturn($this->period(['number' => 2]));
            $m->shouldNotReceive('create');
        });

        $errors = $this->errors(fn () => app(PeriodService::class)->create(['shift_id' => 4, 'number' => 3, 'start_time' => '08:30', 'end_time' => '09:15']));

        $this->assertArrayHasKey('start_time', $errors);
        $this->assertStringContainsString('period 2', $errors['start_time'][0]);
    }

    public function test_create_refuses_an_end_before_the_start_without_querying(): void
    {
        $this->mocks(function (MockInterface $m) {
            $m->shouldNotReceive('findOverlapping');
            $m->shouldNotReceive('create');
        });

        $this->assertArrayHasKey('end_time', $this->errors(fn () => app(PeriodService::class)->create(['shift_id' => 4, 'number' => 1, 'start_time' => '09:00', 'end_time' => '09:00'])));
    }

    public function test_create_reports_a_concurrent_duplicate_number_as_a_validation_error(): void
    {
        $this->mocks(function (MockInterface $m) {
            $m->shouldReceive('create')->once()->andThrow(
                new UniqueConstraintViolationException('sqlite', 'insert into periods', [], new RuntimeException('UNIQUE constraint failed: periods.shift_id, periods.number'))
            );
        });

        $this->assertArrayHasKey('number', $this->errors(fn () => app(PeriodService::class)->create(['shift_id' => 4, 'number' => 1, 'start_time' => '08:00', 'end_time' => '08:45'])));
    }

    public function test_update_checks_the_period_with_the_input_applied(): void
    {
        $period = $this->period();
        $this->mocks(function (MockInterface $m) {
            // Only end_time is sent; the overlap query sees the saved start with the new end.
            $m->shouldReceive('findOverlapping')->once()->with(4, '08:00:00', '09:00:00', 10)->andReturn($this->period(['number' => 2, 'id' => 11]));
            $m->shouldNotReceive('update');
        });

        $this->assertArrayHasKey('start_time', $this->errors(fn () => app(PeriodService::class)->update($period, ['end_time' => '09:00'])));
    }

    public function test_update_refuses_to_make_a_used_period_a_break(): void
    {
        $period = $this->period();
        $this->mocks(function (MockInterface $m) {
            $m->shouldReceive('isUsedInRoutine')->once()->andReturn(true);
            $m->shouldNotReceive('update');
        });

        $this->assertArrayHasKey('is_break', $this->errors(fn () => app(PeriodService::class)->update($period, ['is_break' => true])));
    }

    public function test_moving_a_used_period_checks_each_slot_for_clashes_at_the_new_time(): void
    {
        $period = $this->period();
        $slot = new \App\Models\RoutineSlot(['academic_year_id' => 3, 'section_id' => 20, 'staff_id' => 7, 'day' => 'saturday', 'room_key' => null]);
        $this->mocks(
            function (MockInterface $m) {
                $m->shouldReceive('isUsedInRoutine')->andReturn(true);
                $m->shouldNotReceive('update');
            },
            function (MockInterface $m) use ($slot) {
                $m->shouldReceive('usingPeriod')->andReturn(new \Illuminate\Database\Eloquent\Collection([$slot]));
                $m->shouldReceive('lockAcademicYear')->once()->with(3)->andReturn(new \App\Models\AcademicYear);
                $m->shouldReceive('findTeacherClash')->once()->with(3, 20, 7, 'saturday', '08:30:00', '09:15:00', 10)
                    ->andReturn(new \App\Models\RoutineSlot(['day' => 'saturday']));
            },
        );

        $this->assertArrayHasKey('start_time', $this->errors(fn () => app(PeriodService::class)->update($period, ['start_time' => '08:30', 'end_time' => '09:15'])));
    }

    public function test_moving_a_used_period_locks_the_shift_then_the_affected_years_in_ascending_order(): void
    {
        $period = $this->period();
        $slots = new \Illuminate\Database\Eloquent\Collection([
            new \App\Models\RoutineSlot(['academic_year_id' => 5, 'section_id' => 20, 'day' => 'saturday']),
            new \App\Models\RoutineSlot(['academic_year_id' => 3, 'section_id' => 21, 'day' => 'sunday']),
            new \App\Models\RoutineSlot(['academic_year_id' => 5, 'section_id' => 22, 'day' => 'monday']),
        ]);
        $order = [];

        $this->mock(ShiftRepositoryInterface::class, function (MockInterface $m) use (&$order) {
            $m->shouldReceive('lockForUpdate')->once()->with([4])->andReturnUsing(function () use (&$order) {
                $order[] = 'shift';
            });
        });
        $this->mock(PeriodRepositoryInterface::class, function (MockInterface $m) use ($period) {
            $m->shouldReceive('findOverlapping')->andReturn(null);
            $m->shouldReceive('isUsedInRoutine')->andReturn(true);
            $m->shouldReceive('update')->once()->andReturn($period);
        });
        $this->mock(RoutineRepositoryInterface::class, function (MockInterface $m) use ($slots, &$order) {
            $m->shouldReceive('usingPeriod')->andReturn($slots);
            $m->shouldReceive('lockAcademicYear')->twice()->andReturnUsing(function (int $id) use (&$order) {
                $order[] = "year{$id}";

                return new \App\Models\AcademicYear;
            });
        });

        app(PeriodService::class)->update($period, ['start_time' => '08:30', 'end_time' => '09:15']);

        $this->assertSame(['shift', 'year3', 'year5'], $order);
    }

    public function test_renaming_a_used_period_does_not_recheck_clashes(): void
    {
        $period = $this->period();
        $this->mocks(
            function (MockInterface $m) use ($period) {
                $m->shouldReceive('isUsedInRoutine')->andReturn(true);
                $m->shouldReceive('update')->once()->andReturn($period);
            },
            fn (MockInterface $m) => $m->shouldNotReceive('usingPeriod'),
        );

        app(PeriodService::class)->update($period, ['name_en' => 'First']);
    }

    public function test_delete_is_refused_while_routine_slots_use_the_period(): void
    {
        $period = $this->period();
        $this->mocks(function (MockInterface $m) use ($period) {
            $m->shouldReceive('isUsedInRoutine')->once()->with($period)->andReturn(true);
            $m->shouldNotReceive('delete');
        });

        try {
            app(PeriodService::class)->delete($period);
            $this->fail('Expected a 409 HttpException.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_delete_removes_an_unused_period(): void
    {
        $period = $this->period();
        $this->mocks(function (MockInterface $m) use ($period) {
            $m->shouldReceive('delete')->once()->with($period);
        });

        app(PeriodService::class)->delete($period);
    }
}
