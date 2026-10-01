<?php

namespace Tests\Unit;

use App\Support\Gpa;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Gpa is stateless and pure, so these are plain PHPUnit tests with no framework or database.
 */
class GpaTest extends TestCase
{
    /** One paper with a single written part of $full marks. */
    private function paper(float|int|string|null $obtained, int $full = 100, int $pass = 33, bool $absent = false): array
    {
        return ['absent' => $absent, 'parts' => ['written' => ['obtained' => $obtained, 'full' => $full, 'pass' => $pass]]];
    }

    /** A unit as Gpa::unit() returns it, reduced to what Gpa::result() reads. */
    private function graded(string $grade, string $point, string $obtained = '80.00', string $full = '100.00'): array
    {
        return ['grade' => $grade, 'point' => $point, 'obtained' => $obtained, 'full' => $full];
    }

    private function unitOf(float $obtained): array
    {
        return Gpa::unit([$this->paper($obtained)]);
    }

    // Grade scale

    public static function boundaries(): array
    {
        return [
            [0, 'F', '0.00'],
            [32, 'F', '0.00'],
            [33, 'D', '1.00'],
            [39, 'D', '1.00'],
            [40, 'C', '2.00'],
            [49, 'C', '2.00'],
            [50, 'B', '3.00'],
            [59, 'B', '3.00'],
            [60, 'A-', '3.50'],
            [69, 'A-', '3.50'],
            [70, 'A', '4.00'],
            [79, 'A', '4.00'],
            [80, 'A+', '5.00'],
            [100, 'A+', '5.00'],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_grade_boundaries(int $percentage, string $grade, string $point): void
    {
        $this->assertSame(['grade' => $grade, 'point' => $point], Gpa::gradeFor($percentage));
    }

    public function test_percentages_are_compared_half_up_on_two_decimals(): void
    {
        // 32.995 rounds to 33.00 (a pass), 32.994 to 32.99 (a fail), 79.995 to 80.00.
        $this->assertSame('D', Gpa::gradeFor(32.995)['grade']);
        $this->assertSame('F', Gpa::gradeFor(32.994)['grade']);
        $this->assertSame('A+', Gpa::gradeFor(79.995)['grade']);
        $this->assertSame('A', Gpa::gradeFor(79.994)['grade']);
    }

    public function test_a_unit_grade_comes_from_obtained_over_full(): void
    {
        // 66 of 75 = 88%.
        $unit = Gpa::unit([$this->paper(66, 75, 25)]);

        $this->assertSame('88.00', $unit['percentage']);
        $this->assertSame('A+', $unit['grade']);
        $this->assertSame('5.00', $unit['point']);
        $this->assertSame('66.00', $unit['obtained']);
        $this->assertSame('75.00', $unit['full']);
    }

    public function test_unit_percentage_rounds_half_up_exactly(): void
    {
        // 1599.9 of 2000 is exactly 79.995%, which rounds up to 80.00.
        $unit = Gpa::unit([$this->paper(1599.9, 2000, 660)]);

        $this->assertSame('80.00', $unit['percentage']);
        $this->assertSame('A+', $unit['grade']);
    }

    public function test_a_part_below_its_pass_mark_fails_even_with_a_high_total(): void
    {
        // Written 70/70 but MCQ 5/30 against a pass mark of 10: 75 of 100 would be an A.
        $unit = Gpa::unit([[
            'absent' => false,
            'parts' => [
                'written' => ['obtained' => 70, 'full' => 70, 'pass' => 23],
                'mcq' => ['obtained' => 5, 'full' => 30, 'pass' => 10],
            ],
        ]]);

        $this->assertSame('F', $unit['grade']);
        $this->assertSame('0.00', $unit['point']);
        $this->assertSame('75.00', $unit['obtained']);
        $this->assertSame('75.00', $unit['percentage']);
    }

    public function test_a_part_exactly_on_its_pass_mark_passes(): void
    {
        $unit = Gpa::unit([[
            'absent' => false,
            'parts' => [
                'written' => ['obtained' => 23, 'full' => 70, 'pass' => 23],
                'mcq' => ['obtained' => 10, 'full' => 30, 'pass' => 10],
            ],
        ]]);

        $this->assertSame('D', $unit['grade']);
    }

    public function test_absent_is_a_failure(): void
    {
        $unit = Gpa::unit([$this->paper(null, absent: true)]);

        $this->assertSame('F', $unit['grade']);
        $this->assertSame('0.00', $unit['point']);
        $this->assertTrue($unit['is_absent']);
        $this->assertSame('0.00', $unit['obtained']);
    }

    public function test_marks_on_an_absent_paper_are_ignored(): void
    {
        $unit = Gpa::unit([$this->paper(90, absent: true)]);

        $this->assertSame('F', $unit['grade']);
        $this->assertSame('0.00', $unit['obtained']);
    }

    public function test_a_null_part_counts_as_zero(): void
    {
        $unit = Gpa::unit([$this->paper(null)]);

        $this->assertSame('F', $unit['grade']);
        $this->assertFalse($unit['is_absent']);
    }

    public function test_a_pair_passes_on_combined_marks_while_one_papers_part_is_below_its_own_pass_mark(): void
    {
        // Paper 1: 20/70 written (pass 23), paper 2: 50/70 (pass 23). Combined 70 of 140
        // against a combined pass of 46: passes, although paper 1 alone is under 23.
        $unit = Gpa::unit([$this->paper(20, 70, 23), $this->paper(50, 70, 23)]);

        $this->assertSame('B', $unit['grade']);
        $this->assertSame('50.00', $unit['percentage']);
        $this->assertSame('70.00', $unit['obtained']);
        $this->assertSame('140.00', $unit['full']);
        $this->assertSame(['written' => ['obtained' => '70.00', 'full' => 140, 'pass' => 46]], $unit['parts']);
    }

    public function test_a_pair_fails_when_a_combined_part_is_below_the_combined_pass_mark(): void
    {
        // Written 15 + 20 = 35 against 46, although the total (35 + MCQ 56) is high.
        $paper = fn (float $written, float $mcq) => [
            'absent' => false,
            'parts' => [
                'written' => ['obtained' => $written, 'full' => 70, 'pass' => 23],
                'mcq' => ['obtained' => $mcq, 'full' => 30, 'pass' => 10],
            ],
        ];

        $unit = Gpa::unit([$paper(15, 28), $paper(20, 28)]);

        $this->assertSame('F', $unit['grade']);
        $this->assertSame('0.00', $unit['point']);
        $this->assertSame('91.00', $unit['obtained']);
    }

    public function test_a_pair_fails_when_absent_from_either_paper(): void
    {
        $unit = Gpa::unit([$this->paper(90), $this->paper(null, absent: true)]);

        $this->assertSame('F', $unit['grade']);
        $this->assertTrue($unit['is_absent']);
    }

    public function test_a_single_paper_group_row_is_graded_alone(): void
    {
        // One paper in a paper group: nothing is added to it and it is graded on its own marks.
        $unit = Gpa::unit([$this->paper(45)]);

        $this->assertSame('C', $unit['grade']);
        $this->assertSame('100.00', $unit['full']);
        $this->assertSame('45.00', $unit['percentage']);
    }

    // GPA

    public function test_the_gpa_is_the_average_of_the_compulsory_points(): void
    {
        $result = Gpa::result([
            $this->graded('A+', '5.00'), $this->graded('A', '4.00'), $this->graded('B', '3.00'),
        ]);

        $this->assertSame('4.00', $result['gpa']);
        $this->assertSame('A', $result['grade']);
        $this->assertTrue($result['is_pass']);
        $this->assertSame(0, $result['failed_count']);
    }

    public function test_a_4th_subject_a_plus_adds_three_over_the_number_of_compulsory_units(): void
    {
        // Four B's average 3.00; an A+ 4th subject adds (5 - 2) / 4 = 0.75.
        $compulsory = array_fill(0, 4, $this->graded('B', '3.00'));

        $result = Gpa::result($compulsory, $this->graded('A+', '5.00'));

        $this->assertSame('3.75', $result['gpa']);
        $this->assertSame('A-', $result['grade']);
    }

    public function test_a_4th_subject_d_adds_nothing(): void
    {
        $compulsory = array_fill(0, 4, $this->graded('B', '3.00'));

        $this->assertSame('3.00', Gpa::result($compulsory, $this->graded('D', '1.00'))['gpa']);
        // Exactly 2.00 adds nothing either.
        $this->assertSame('3.00', Gpa::result($compulsory, $this->graded('C', '2.00'))['gpa']);
    }

    public function test_a_failed_4th_subject_does_not_fail_the_student(): void
    {
        $compulsory = array_fill(0, 4, $this->graded('A', '4.00'));

        $result = Gpa::result($compulsory, $this->graded('F', '0.00', '10.00'));

        $this->assertSame('4.00', $result['gpa']);
        $this->assertSame('A', $result['grade']);
        $this->assertTrue($result['is_pass']);
        $this->assertSame(0, $result['failed_count']);
    }

    public function test_one_compulsory_f_gives_a_gpa_of_zero_and_grade_f(): void
    {
        $result = Gpa::result([
            $this->graded('A+', '5.00'), $this->graded('F', '0.00', '20.00'), $this->graded('A+', '5.00'),
        ], $this->graded('A+', '5.00'));

        $this->assertSame('0.00', $result['gpa']);
        $this->assertSame('F', $result['grade']);
        $this->assertFalse($result['is_pass']);
        $this->assertSame(1, $result['failed_count']);
    }

    public function test_failed_count_counts_every_failed_compulsory_unit(): void
    {
        $result = Gpa::result(array_fill(0, 3, $this->graded('F', '0.00', '0.00')), $this->graded('F', '0.00', '0.00'));

        $this->assertSame(3, $result['failed_count']);
    }

    public function test_the_gpa_is_capped_at_five(): void
    {
        // Five A+ plus an A+ 4th subject: (25 + 3) / 5 = 5.60, capped.
        $result = Gpa::result(array_fill(0, 5, $this->graded('A+', '5.00')), $this->graded('A+', '5.00'));

        $this->assertSame('5.00', $result['gpa']);
        $this->assertSame('A+', $result['grade']);
    }

    public function test_the_gpa_rounds_half_up(): void
    {
        // 5 * 5.00 + 4.00 + 3.00 + 1.00 = 33.00 over eight units is exactly 4.125.
        $units = [
            ...array_fill(0, 5, $this->graded('A+', '5.00')),
            $this->graded('A', '4.00'),
            $this->graded('B', '3.00'),
            $this->graded('D', '1.00'),
        ];

        $this->assertSame('4.13', Gpa::result($units)['gpa']);
    }

    public function test_the_gpa_rounds_to_the_nearest_hundredth(): void
    {
        // 5 + 5 + 4 = 14 / 3 = 4.666..., which rounds to 4.67.
        $this->assertSame('4.67', Gpa::result([
            $this->graded('A+', '5.00'), $this->graded('A+', '5.00'), $this->graded('A', '4.00'),
        ])['gpa']);
        // 5 + 4 + 4 = 13 / 3 = 4.333..., rounds to 4.33.
        $this->assertSame('4.33', Gpa::result([
            $this->graded('A+', '5.00'), $this->graded('A', '4.00'), $this->graded('A', '4.00'),
        ])['gpa']);
    }

    public function test_class_one_to_eight_have_no_4th_subject(): void
    {
        $result = Gpa::result([
            $this->graded('A+', '5.00'), $this->graded('A-', '3.50'), $this->graded('B', '3.00'),
        ]);

        // 11.5 / 3 = 3.8333...
        $this->assertSame('3.83', $result['gpa']);
        $this->assertSame('A-', $result['grade']);
    }

    public function test_totals_include_the_4th_subject(): void
    {
        $result = Gpa::result(
            [$this->graded('A+', '5.00', '90.00'), $this->graded('A', '4.00', '75.50')],
            $this->graded('B', '3.00', '55.00', '100.00'),
        );

        $this->assertSame('220.50', $result['total_obtained']);
        $this->assertSame('300.00', $result['total_full']);
    }

    public function test_a_student_with_no_compulsory_units_fails(): void
    {
        $result = Gpa::result([]);

        $this->assertSame('0.00', $result['gpa']);
        $this->assertSame('F', $result['grade']);
        $this->assertFalse($result['is_pass']);
    }

    public static function gpaGrades(): array
    {
        return [
            ['5.00', 'A+'], ['4.99', 'A'], ['4.00', 'A'], ['3.99', 'A-'], ['3.50', 'A-'], ['3.49', 'B'],
            ['3.00', 'B'], ['2.99', 'C'], ['2.00', 'C'], ['1.99', 'D'], ['1.00', 'D'], ['0.99', 'F'], ['0.00', 'F'],
        ];
    }

    #[DataProvider('gpaGrades')]
    public function test_the_overall_grade_from_the_gpa(string $gpa, string $grade): void
    {
        $this->assertSame($grade, Gpa::gradeForGpa($gpa));
    }

    public function test_the_unit_helper_feeds_the_gpa(): void
    {
        $units = [$this->unitOf(85), $this->unitOf(72), $this->unitOf(61)];

        // 5.00 + 4.00 + 3.50 = 12.50 / 3 = 4.1666...
        $this->assertSame('4.17', Gpa::result($units)['gpa']);
    }

    // Merit positions

    private function row(int $key, string $gpa, int $passed, string $total): array
    {
        return ['key' => $key, 'gpa' => $gpa, 'passed_count' => $passed, 'total' => $total];
    }

    public function test_ties_share_a_position_and_the_next_one_skips(): void
    {
        $positions = Gpa::positions([
            $this->row(1, '5.00', 8, '900.00'),
            $this->row(2, '4.50', 8, '800.00'),
            $this->row(3, '4.50', 8, '800.00'),
            $this->row(4, '4.00', 8, '700.00'),
        ]);

        $this->assertSame([1 => 1, 2 => 2, 3 => 2, 4 => 4], $positions);
    }

    public function test_the_order_is_gpa_then_passed_subjects_then_total(): void
    {
        $positions = Gpa::positions([
            $this->row(1, '4.00', 7, '700.00'),
            $this->row(2, '4.00', 8, '650.00'),
            $this->row(3, '4.00', 8, '750.00'),
            $this->row(4, '5.00', 6, '600.00'),
        ]);

        $this->assertSame([4 => 1, 3 => 2, 2 => 3, 1 => 4], $positions);
    }

    public function test_a_student_with_a_failed_subject_ranks_below_one_who_passed_all_despite_a_higher_total(): void
    {
        // 700 with 1 failed subject (GPA 0.00) against 690 with every subject passed.
        $positions = Gpa::positions([
            $this->row(1, '0.00', 7, '700.00'),
            $this->row(2, '1.00', 8, '690.00'),
        ]);

        $this->assertSame([2 => 1, 1 => 2], $positions);
    }

    public function test_failed_students_rank_by_subjects_passed_before_total(): void
    {
        $positions = Gpa::positions([
            $this->row(1, '0.00', 5, '700.00'),
            $this->row(2, '0.00', 7, '690.00'),
        ]);

        $this->assertSame([2 => 1, 1 => 2], $positions);
    }

    public function test_equal_gpa_and_passed_count_fall_back_to_the_total(): void
    {
        $positions = Gpa::positions([
            $this->row(1, '3.00', 8, '600.00'),
            $this->row(2, '3.00', 8, '650.00'),
        ]);

        $this->assertSame([2 => 1, 1 => 2], $positions);
    }

    public function test_a_failed_4th_subject_changes_neither_passed_count_nor_the_gpa(): void
    {
        $compulsory = array_fill(0, 4, $this->graded('A', '4.00'));

        $passed4th = Gpa::result($compulsory, $this->graded('C', '2.00'));
        $failed4th = Gpa::result($compulsory, $this->graded('F', '0.00', '10.00'));

        $this->assertSame(4, $passed4th['passed_count']);
        $this->assertSame(4, $failed4th['passed_count']);
        $this->assertSame(0, $failed4th['failed_count']);
        $this->assertSame($passed4th['gpa'], $failed4th['gpa']);
        $this->assertTrue($failed4th['is_pass']);

        $positions = Gpa::positions([
            ['key' => 1, 'gpa' => $failed4th['gpa'], 'passed_count' => $failed4th['passed_count'], 'total' => '400.00'],
            ['key' => 2, 'gpa' => $passed4th['gpa'], 'passed_count' => $passed4th['passed_count'], 'total' => '410.00'],
        ]);
        // Equal GPA and passed_count: the passed 4th subject's marks lift the total.
        $this->assertSame([2 => 1, 1 => 2], $positions);
    }

    public function test_a_passed_4th_subject_above_2_00_gives_a_higher_gpa_and_rank(): void
    {
        $compulsory = array_fill(0, 4, $this->graded('A', '4.00'));
        $bonus = Gpa::result($compulsory, $this->graded('A+', '5.00'));
        $failed = Gpa::result($compulsory, $this->graded('F', '0.00', '10.00'));

        $this->assertSame($failed['passed_count'], $bonus['passed_count']);
        $this->assertSame('4.75', $bonus['gpa']);
        $this->assertSame('4.00', $failed['gpa']);
        $this->assertSame(
            [2 => 1, 1 => 2],
            Gpa::positions([
                ['key' => 1, 'gpa' => $failed['gpa'], 'passed_count' => $failed['passed_count'], 'total' => '500.00'],
                ['key' => 2, 'gpa' => $bonus['gpa'], 'passed_count' => $bonus['passed_count'], 'total' => '400.00'],
            ])
        );
    }

    public function test_passed_count_counts_every_compulsory_unit_that_is_not_an_f(): void
    {
        $result = Gpa::result([$this->graded('A', '4.00'), $this->graded('F', '0.00', '10.00'), $this->graded('D', '1.00')]);

        $this->assertSame(2, $result['passed_count']);
        $this->assertSame(1, $result['failed_count']);
    }

    public function test_a_combined_pair_counts_as_one_passed_or_failed_unit(): void
    {
        $paper = fn (float $written) => ['absent' => false, 'parts' => ['written' => ['obtained' => $written, 'full' => 50, 'pass' => 17]]];

        $passed = Gpa::unit([$paper(40), $paper(40)]);
        $failed = Gpa::unit([$paper(5), $paper(5)]);

        $this->assertSame(1, Gpa::result([$passed])['passed_count']);
        $this->assertSame(0, Gpa::result([$failed])['passed_count']);
        $this->assertSame(1, Gpa::result([$failed])['failed_count']);
    }

    public function test_input_order_never_affects_a_position(): void
    {
        $rows = [
            $this->row(1, true, '3.00', '500.00'),
            $this->row(2, true, '3.00', '500.00'),
            $this->row(3, true, '3.00', '500.00'),
        ];

        $this->assertSame([1 => 1, 2 => 1, 3 => 1], Gpa::positions($rows));
        $this->assertSame([3 => 1, 2 => 1, 1 => 1], Gpa::positions(array_reverse($rows)));
    }

    public function test_no_students_means_no_positions(): void
    {
        $this->assertSame([], Gpa::positions([]));
    }
}
