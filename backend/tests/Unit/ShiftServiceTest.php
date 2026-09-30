<?php

namespace Tests\Unit;

use App\Models\Shift;
use App\Repositories\Contracts\ShiftRepositoryInterface;
use App\Services\ShiftService;
use Mockery\MockInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface — no database.
 */
class ShiftServiceTest extends TestCase
{
    public function test_delete_is_refused_when_shift_has_staff(): void
    {
        $shift = new Shift;

        $this->mock(ShiftRepositoryInterface::class, function (MockInterface $mock) use ($shift) {
            $mock->shouldReceive('hasStaff')->once()->with($shift)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(ShiftService::class)->delete($shift));
    }

    /**
     * Regression test: ShiftService::delete() used to check only hasStaff(), even
     * though sections.shift_id also references shifts (restrictOnDelete).
     */
    public function test_delete_is_refused_when_shift_is_used_by_sections(): void
    {
        $shift = new Shift;

        $this->mock(ShiftRepositoryInterface::class, function (MockInterface $mock) use ($shift) {
            $mock->shouldReceive('hasStaff')->once()->with($shift)->andReturn(false);
            $mock->shouldReceive('isUsedBySections')->once()->with($shift)->andReturn(true);
            $mock->shouldNotReceive('delete');
        });

        $this->assertConflict(fn () => app(ShiftService::class)->delete($shift));
    }

    public function test_delete_removes_an_unused_shift(): void
    {
        $shift = new Shift;

        $this->mock(ShiftRepositoryInterface::class, function (MockInterface $mock) use ($shift) {
            $mock->shouldReceive('hasStaff')->once()->with($shift)->andReturn(false);
            $mock->shouldReceive('isUsedBySections')->once()->with($shift)->andReturn(false);
            $mock->shouldReceive('delete')->once()->with($shift);
        });

        app(ShiftService::class)->delete($shift);
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
