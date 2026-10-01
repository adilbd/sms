<?php

namespace Tests\Unit;

use App\Models\Classes;
use App\Models\FeeHead;
use App\Models\FeeRate;
use App\Models\StudentFeeWaiver;
use App\Repositories\Contracts\ClassRepositoryInterface;
use App\Repositories\Contracts\FeeHeadRepositoryInterface;
use App\Repositories\Contracts\FeeRateRepositoryInterface;
use App\Repositories\Contracts\FeeWaiverRepositoryInterface;
use App\Services\FeeHeadService;
use App\Services\FeeRateService;
use App\Services\FeeWaiverService;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The fee head, rate and waiver services against mocked repository interfaces, no database.
 */
class FeeCatalogServicesTest extends TestCase
{
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
            $this->fail('Expected a 409.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    // --- heads -------------------------------------------------------------------------

    public function test_a_head_needs_a_name_in_either_language(): void
    {
        $this->mock(FeeHeadRepositoryInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('create'));

        $this->assertArrayHasKey('name_en', $this->errors(fn () => app(FeeHeadService::class)->create(['code' => 'X', 'kind' => 'monthly', 'name_en' => '', 'name_bn' => null])));
    }

    public function test_a_head_with_rates_or_dues_cannot_be_deleted(): void
    {
        $head = new FeeHead;

        $this->mock(FeeHeadRepositoryInterface::class, function (MockInterface $m) use ($head) {
            $m->shouldReceive('hasRates')->once()->with($head)->andReturn(true);
            $m->shouldNotReceive('hasDues', 'delete');
        });
        $this->assertConflict(fn () => app(FeeHeadService::class)->delete($head));

        $this->mock(FeeHeadRepositoryInterface::class, function (MockInterface $m) {
            $m->shouldReceive('hasRates')->once()->andReturn(false);
            $m->shouldReceive('hasDues')->once()->andReturn(true);
            $m->shouldNotReceive('delete');
        });
        $this->assertConflict(fn () => app(FeeHeadService::class)->delete($head));
    }

    public function test_an_unused_head_is_deleted(): void
    {
        $head = new FeeHead;

        $this->mock(FeeHeadRepositoryInterface::class, function (MockInterface $m) use ($head) {
            $m->shouldReceive('hasRates')->andReturn(false);
            $m->shouldReceive('hasDues')->andReturn(false);
            $m->shouldReceive('delete')->once()->with($head);
        });

        app(FeeHeadService::class)->delete($head);
    }

    // --- rates -------------------------------------------------------------------------

    private function class(int $number): Classes
    {
        return new Classes(['number' => $number]);
    }

    public function test_a_rate_group_needs_class_9_or_above(): void
    {
        $this->mock(ClassRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findOrFail')->with(8)->andReturn($this->class(8)));
        $this->mock(FeeRateRepositoryInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('create'));

        $errors = $this->errors(fn () => app(FeeRateService::class)->create(['fee_head_id' => 1, 'class_id' => 8, 'academic_year_id' => 1, 'group' => 'science', 'amount' => '500']));

        $this->assertArrayHasKey('group', $errors);
    }

    public function test_a_duplicate_rate_is_refused_with_a_null_group_compared_as_null(): void
    {
        $this->mock(ClassRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findOrFail')->andReturn($this->class(10)));
        $this->mock(FeeRateRepositoryInterface::class, function (MockInterface $m) {
            $m->shouldReceive('existsFor')->once()->with(1, 10, 3, null, null)->andReturn(true);
            $m->shouldNotReceive('create');
        });

        $errors = $this->errors(fn () => app(FeeRateService::class)->create(['fee_head_id' => 1, 'class_id' => 10, 'academic_year_id' => 3, 'amount' => '500']));

        $this->assertArrayHasKey('fee_head_id', $errors);
    }

    public function test_a_rate_used_by_dues_cannot_be_deleted(): void
    {
        $rate = new FeeRate;

        $this->mock(ClassRepositoryInterface::class);
        $this->mock(FeeRateRepositoryInterface::class, function (MockInterface $m) use ($rate) {
            $m->shouldReceive('hasDues')->once()->with($rate)->andReturn(true);
            $m->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(FeeRateService::class)->delete($rate));
    }

    public function test_a_partial_rate_update_is_checked_against_the_saved_class_and_excludes_itself(): void
    {
        $rate = new FeeRate(['fee_head_id' => 1, 'class_id' => 10, 'academic_year_id' => 3, 'group' => null, 'amount' => '500']);
        $rate->id = 5;

        $this->mock(ClassRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findOrFail')->with(10)->andReturn($this->class(10)));
        $this->mock(FeeRateRepositoryInterface::class, function (MockInterface $m) use ($rate) {
            $m->shouldReceive('existsFor')->once()->with(1, 10, 3, 'science', 5)->andReturn(false);
            $m->shouldReceive('update')->once()->andReturn($rate);
            $m->shouldReceive('loadDetail')->once()->andReturn($rate);
        });

        app(FeeRateService::class)->update($rate, ['group' => 'science']);
    }

    // --- waivers -----------------------------------------------------------------------

    public function test_a_waiver_needs_exactly_one_of_percent_and_fixed_amount(): void
    {
        $this->mock(FeeWaiverRepositoryInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('create', 'existsFor'));

        foreach ([['percent' => 10, 'fixed_amount' => 100], ['percent' => null, 'fixed_amount' => null], []] as $amounts) {
            $errors = $this->errors(fn () => app(FeeWaiverService::class)->create(['student_id' => 1, 'academic_year_id' => 1, 'fee_head_id' => 1, 'approved_by' => 1, ...$amounts]));
            $this->assertArrayHasKey('percent', $errors);
        }
    }

    public function test_a_waiver_of_zero_percent_counts_as_a_percent(): void
    {
        $waiver = new StudentFeeWaiver;
        $this->mock(FeeWaiverRepositoryInterface::class, function (MockInterface $m) use ($waiver) {
            $m->shouldReceive('existsFor')->once()->andReturn(false);
            $m->shouldReceive('create')->once()->andReturn($waiver);
            $m->shouldReceive('loadDetail')->once()->andReturn($waiver);
        });

        app(FeeWaiverService::class)->create(['student_id' => 1, 'academic_year_id' => 1, 'fee_head_id' => 1, 'approved_by' => 1, 'percent' => 0]);
    }

    public function test_a_second_waiver_for_the_same_student_year_and_head_is_refused(): void
    {
        $this->mock(FeeWaiverRepositoryInterface::class, function (MockInterface $m) {
            $m->shouldReceive('existsFor')->once()->with(1, 2, 3, null)->andReturn(true);
            $m->shouldNotReceive('create');
        });

        $errors = $this->errors(fn () => app(FeeWaiverService::class)->create(['student_id' => 1, 'academic_year_id' => 2, 'fee_head_id' => 3, 'approved_by' => 1, 'percent' => 10]));

        $this->assertArrayHasKey('fee_head_id', $errors);
    }
}
