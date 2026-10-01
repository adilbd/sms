<?php

namespace Tests\Unit;

use App\Models\AcademicYear;
use App\Models\Exam;
use App\Models\FeeHead;
use App\Models\FeeRate;
use App\Models\StudentEnrolment;
use App\Models\StudentFeeWaiver;
use App\Repositories\Contracts\AcademicYearRepositoryInterface;
use App\Repositories\Contracts\ExamRepositoryInterface;
use App\Repositories\Contracts\FeeDueRepositoryInterface;
use App\Repositories\Contracts\FeeHeadRepositoryInterface;
use App\Repositories\Contracts\FeeRateRepositoryInterface;
use App\Repositories\Contracts\FeeWaiverRepositoryInterface;
use App\Repositories\Contracts\StudentEnrolmentRepositoryInterface;
use App\Services\FeeDueService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * FeeDueService against mocked repository interfaces, no database: which dues a
 * generation builds (amounts, waivers in paisa, periods, due dates) and what it hands to
 * the repository to insert.
 */
class FeeDueServiceTest extends TestCase
{
    private function year(int $id = 3, int $year = 2026): AcademicYear
    {
        $academicYear = new AcademicYear(['year' => $year]);
        $academicYear->id = $id;

        return $academicYear;
    }

    private function feeHead(int $id, string $kind, bool $active = true): FeeHead
    {
        $head = new FeeHead(['kind' => $kind, 'is_active' => $active]);
        $head->id = $id;

        return $head;
    }

    private function rate(int $headId, int $classId, string $amount, ?string $group = null, ?int $dueDay = null): FeeRate
    {
        return new FeeRate(['fee_head_id' => $headId, 'class_id' => $classId, 'academic_year_id' => 3, 'group' => $group, 'amount' => $amount, 'due_day' => $dueDay]);
    }

    private function enrolment(int $id, int $studentId, int $classId = 10, ?string $group = null, ?string $enrolledOn = null): StudentEnrolment
    {
        $enrolment = new StudentEnrolment(['student_id' => $studentId, 'class_id' => $classId, 'group' => $group, 'enrolled_on' => $enrolledOn]);
        $enrolment->id = $id;

        return $enrolment;
    }

    private function waiver(int $studentId, int $headId, ?string $percent, ?string $fixed = null): StudentFeeWaiver
    {
        return new StudentFeeWaiver(['student_id' => $studentId, 'fee_head_id' => $headId, 'percent' => $percent, 'fixed_amount' => $fixed]);
    }

    /**
     * @param  list<FeeHead>  $heads
     * @param  list<StudentEnrolment>  $enrolments
     * @param  list<FeeRate>  $rates
     * @param  list<StudentFeeWaiver>  $waivers
     * @param  array<string, true>  $existing
     */
    private function arrange(array $heads, array $enrolments, array $rates, array $waivers = [], array $existing = [], ?AcademicYear $year = null): void
    {
        $this->mock(AcademicYearRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('findOrFail')->andReturn($year ?? $this->year()));
        $this->mock(FeeHeadRepositoryInterface::class, function (MockInterface $m) use ($heads) {
            $m->shouldReceive('active')->andReturn(new Collection($heads));
            $m->shouldReceive('findOrFail')->andReturnUsing(fn (int $id) => collect($heads)->firstWhere('id', $id));
        });
        $this->mock(StudentEnrolmentRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('activeInYear')->andReturn(new Collection($enrolments)));
        $this->mock(FeeRateRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('forGeneration')->andReturn(new Collection($rates)));
        $this->mock(FeeWaiverRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('forGeneration')->andReturn(new Collection($waivers)));
        $this->mock(FeeDueRepositoryInterface::class, fn (MockInterface $m) => $m->shouldReceive('existingKeys')->andReturn($existing));
        $this->mock(ExamRepositoryInterface::class);
    }

    /** Captures the rows the repository is asked to insert. */
    private function capture(array &$rows, ?int $returns = null): void
    {
        $this->mock(FeeDueRepositoryInterface::class, function (MockInterface $m) use (&$rows, $returns) {
            $m->shouldReceive('existingKeys')->andReturn([]);
            $m->shouldReceive('insertMany')->once()->andReturnUsing(function (array $given) use (&$rows, $returns) {
                $rows = $given;

                return $returns ?? count($given);
            });
        });
    }

    public function test_it_builds_one_due_per_enrolment_with_the_rates_amount_and_due_date(): void
    {
        $this->arrange([$this->feeHead(1, 'monthly')], [$this->enrolment(1, 11), $this->enrolment(2, 12)], [$this->rate(1, 10, '800.00', null, 7)]);
        $rows = [];
        $this->capture($rows);

        $result = app(FeeDueService::class)->generate(['academic_year_id' => 3, 'month' => '2026-10']);

        $this->assertSame(['created' => 2, 'skipped' => 0, 'no_rate' => 0], $result);
        $this->assertSame([1, 2], array_column($rows, 'enrolment_id'));
        $this->assertSame(['2026-10', '2026-10'], array_column($rows, 'period'));
        $this->assertSame('2026-10-07', $rows[0]['due_date']);
        $this->assertSame(['800.00', '0.00', '800.00', '0.00', 'unpaid'], [$rows[0]['amount'], $rows[0]['waiver_amount'], $rows[0]['net_amount'], $rows[0]['paid_amount'], $rows[0]['status']]);
    }

    public function test_the_due_date_defaults_to_the_10th(): void
    {
        $this->arrange([$this->feeHead(1, 'monthly')], [$this->enrolment(1, 11)], [$this->rate(1, 10, '800.00')]);
        $rows = [];
        $this->capture($rows);

        app(FeeDueService::class)->generate(['academic_year_id' => 3, 'month' => '2026-02']);

        $this->assertSame('2026-02-10', $rows[0]['due_date']);
    }

    public function test_waivers_are_worked_out_in_integer_paisa_and_capped(): void
    {
        $this->arrange(
            [$this->feeHead(1, 'monthly')],
            [$this->enrolment(1, 11), $this->enrolment(2, 12), $this->enrolment(3, 13), $this->enrolment(4, 14), $this->enrolment(5, 15)],
            [$this->rate(1, 10, '1000.00')],
            [
                $this->waiver(11, 1, '33.33'),
                $this->waiver(12, 1, '100.00'),
                $this->waiver(13, 1, null, '2500.00'),
                $this->waiver(14, 1, null, '125.50'),
            ],
        );
        $rows = [];
        $this->capture($rows);

        app(FeeDueService::class)->generate(['academic_year_id' => 3, 'month' => '2026-10']);

        $byEnrolment = collect($rows)->keyBy('enrolment_id');
        // 33.33% of 1000.00 is 333.30.
        $this->assertSame(['333.30', '666.70', 'unpaid'], [$byEnrolment[1]['waiver_amount'], $byEnrolment[1]['net_amount'], $byEnrolment[1]['status']]);
        $this->assertSame(['1000.00', '0.00', 'waived'], [$byEnrolment[2]['waiver_amount'], $byEnrolment[2]['net_amount'], $byEnrolment[2]['status']]);
        // A fixed waiver above the amount is capped at it.
        $this->assertSame(['1000.00', '0.00', 'waived'], [$byEnrolment[3]['waiver_amount'], $byEnrolment[3]['net_amount'], $byEnrolment[3]['status']]);
        $this->assertSame(['125.50', '874.50'], [$byEnrolment[4]['waiver_amount'], $byEnrolment[4]['net_amount']]);
        $this->assertSame(['0.00', '1000.00'], [$byEnrolment[5]['waiver_amount'], $byEnrolment[5]['net_amount']]);
    }

    public function test_a_group_rate_beats_the_class_rate_and_a_missing_rate_makes_no_due(): void
    {
        $this->arrange(
            [$this->feeHead(1, 'monthly')],
            [$this->enrolment(1, 11, 10, 'science'), $this->enrolment(2, 12, 10, 'humanities'), $this->enrolment(3, 13, 8)],
            [$this->rate(1, 10, '900.00'), $this->rate(1, 10, '1100.00', 'science')],
        );
        $rows = [];
        $this->capture($rows);

        $result = app(FeeDueService::class)->generate(['academic_year_id' => 3, 'month' => '2026-10']);

        $this->assertSame(['created' => 2, 'skipped' => 0, 'no_rate' => 1], $result);
        $this->assertSame(['1100.00', '900.00'], array_column($rows, 'amount'));
    }

    public function test_a_zero_rate_makes_no_due(): void
    {
        $this->arrange([$this->feeHead(1, 'monthly')], [$this->enrolment(1, 11)], [$this->rate(1, 10, '0.00')]);
        $this->mock(FeeDueRepositoryInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('insertMany'));

        $result = app(FeeDueService::class)->generate(['academic_year_id' => 3, 'month' => '2026-10']);

        $this->assertSame(['created' => 0, 'skipped' => 0, 'no_rate' => 1], $result);
    }

    public function test_existing_dues_are_skipped_and_not_inserted_again(): void
    {
        $this->arrange([$this->feeHead(1, 'monthly')], [$this->enrolment(1, 11), $this->enrolment(2, 12)], [$this->rate(1, 10, '800.00')]);
        $inserted = [];
        $this->mock(FeeDueRepositoryInterface::class, function (MockInterface $m) use (&$inserted) {
            $m->shouldReceive('existingKeys')->andReturn(['1|1|2026-10' => true]);
            $m->shouldReceive('insertMany')->once()->andReturnUsing(function (array $rows) use (&$inserted) {
                $inserted = $rows;

                return count($rows);
            });
        });

        $result = app(FeeDueService::class)->generate(['academic_year_id' => 3, 'month' => '2026-10']);

        $this->assertSame(['created' => 1, 'skipped' => 1, 'no_rate' => 0], $result);
        $this->assertSame([2], array_column($inserted, 'enrolment_id'));
    }

    public function test_the_created_count_is_what_the_repository_really_inserted(): void
    {
        // A concurrent run inserted one of the two between the check and the insert.
        $this->arrange([$this->feeHead(1, 'monthly')], [$this->enrolment(1, 11), $this->enrolment(2, 12)], [$this->rate(1, 10, '800.00')]);
        $rows = [];
        $this->capture($rows, 1);

        $result = app(FeeDueService::class)->generate(['academic_year_id' => 3, 'month' => '2026-10']);

        $this->assertSame(['created' => 1, 'skipped' => 1, 'no_rate' => 0], $result);
    }

    public function test_a_dry_run_never_inserts(): void
    {
        $this->arrange([$this->feeHead(1, 'monthly')], [$this->enrolment(1, 11)], [$this->rate(1, 10, '800.00')]);
        $this->mock(FeeDueRepositoryInterface::class, function (MockInterface $m) {
            $m->shouldReceive('existingKeys')->andReturn([]);
            $m->shouldNotReceive('insertMany');
        });

        $this->assertSame(['created' => 1, 'skipped' => 0, 'no_rate' => 0], app(FeeDueService::class)->generate(['academic_year_id' => 3, 'month' => '2026-10'], dryRun: true));
    }

    public function test_without_a_month_a_current_year_generates_up_to_the_current_dhaka_month(): void
    {
        $this->travelTo('2026-03-31 20:00:00'); // April 1st in Dhaka
        $this->arrange([$this->feeHead(1, 'monthly')], [$this->enrolment(1, 11)], [$this->rate(1, 10, '800.00')]);
        $rows = [];
        $this->capture($rows);

        app(FeeDueService::class)->generate(['academic_year_id' => 3]);

        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04'], array_column($rows, 'period'));
    }

    public function test_a_past_year_generates_all_twelve_months_and_a_future_year_none(): void
    {
        $this->travelTo('2026-03-10 06:00:00');
        $this->arrange([$this->feeHead(1, 'monthly')], [$this->enrolment(1, 11)], [$this->rate(1, 10, '800.00')], year: $this->year(2, 2025));
        $rows = [];
        $this->capture($rows);

        app(FeeDueService::class)->generate(['academic_year_id' => 2]);
        $this->assertCount(12, $rows);

        $this->arrange([$this->feeHead(1, 'monthly')], [$this->enrolment(1, 11)], [$this->rate(1, 10, '800.00')], year: $this->year(4, 2027));
        $this->mock(FeeDueRepositoryInterface::class, fn (MockInterface $m) => $m->shouldNotReceive('insertMany', 'existingKeys'));

        $this->assertSame(['created' => 0, 'skipped' => 0, 'no_rate' => 0], app(FeeDueService::class)->generate(['academic_year_id' => 4]));
    }

    public function test_a_student_who_joined_part_way_owes_no_earlier_month(): void
    {
        $this->travelTo('2026-10-15 06:00:00');
        $this->arrange([$this->feeHead(1, 'monthly')], [$this->enrolment(1, 11, 10, null, '2026-08-20'), $this->enrolment(2, 12)], [$this->rate(1, 10, '800.00')]);
        $rows = [];
        $this->capture($rows);

        app(FeeDueService::class)->generate(['academic_year_id' => 3]);

        $this->assertSame(['2026-08', '2026-09', '2026-10'], collect($rows)->where('enrolment_id', 1)->pluck('period')->all());
        $this->assertCount(10, collect($rows)->where('enrolment_id', 2));
    }

    public function test_a_month_outside_the_academic_year_is_refused(): void
    {
        $this->arrange([$this->feeHead(1, 'monthly')], [], []);

        try {
            app(FeeDueService::class)->generate(['academic_year_id' => 3, 'month' => '2027-01']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('month', $e->errors());
        }
    }

    public function test_a_one_time_head_uses_the_one_time_period(): void
    {
        $this->travelTo('2026-10-15 06:00:00');
        $this->arrange([$this->feeHead(2, 'one_time')], [$this->enrolment(1, 11)], [$this->rate(2, 10, '1500.00', null, 20)]);
        $rows = [];
        $this->capture($rows);

        app(FeeDueService::class)->generate(['academic_year_id' => 3]);

        $this->assertSame(['one_time'], array_column($rows, 'period'));
        $this->assertSame('2026-10-20', $rows[0]['due_date']);
    }

    public function test_a_per_exam_head_needs_the_exam_and_uses_its_period_and_start_date(): void
    {
        $exam = new Exam(['academic_year_id' => 3, 'start_date' => '2026-06-15']);
        $exam->id = 9;
        $this->arrange([$this->feeHead(3, 'per_exam'), $this->feeHead(1, 'monthly')], [$this->enrolment(1, 11)], [$this->rate(3, 10, '300.00')]);
        $this->mock(ExamRepositoryInterface::class, function (MockInterface $m) use ($exam) {
            $m->shouldReceive('findOrFail')->with(9)->andReturn($exam);
            $m->shouldReceive('classIds')->with($exam)->andReturn([10]);
        });
        $rows = [];
        $this->capture($rows);

        app(FeeDueService::class)->generate(['academic_year_id' => 3, 'exam_id' => 9]);

        // With an exam only the per-exam head is generated.
        $this->assertSame(['exam:9'], array_column($rows, 'period'));
        $this->assertSame([3], array_column($rows, 'fee_head_id'));
        $this->assertSame('2026-06-15', $rows[0]['due_date']);
    }

    public function test_head_and_exam_mismatches_are_refused(): void
    {
        $this->arrange([$this->feeHead(1, 'monthly'), $this->feeHead(3, 'per_exam'), $this->feeHead(4, 'monthly', active: false)], [$this->enrolment(1, 11)], []);

        foreach ([['fee_head_id' => 3], ['fee_head_id' => 4]] as $extra) {
            try {
                app(FeeDueService::class)->generate(['academic_year_id' => 3, 'month' => '2026-10', ...$extra]);
                $this->fail('Expected a ValidationException.');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }
}
