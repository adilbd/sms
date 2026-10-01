<?php

namespace App\Support;

/**
 * The Bangladesh grading rules (see CLAUDE.md): subject grades, combined paper pairs, the
 * 4th-subject bonus, the GPA, the overall grade and merit positions. Stateless and free of
 * database access, like Seo. App\Services\ResultService feeds it the marks.
 *
 * All arithmetic is done on integers (marks and percentages in hundredths, points in
 * hundredths), so half-up rounding on 2 decimals is exact and never depends on float
 * representation.
 */
class Gpa
{
    /** The marked parts, in order. Mirrors ClassSubject::PARTS. */
    private const PARTS = ['written', 'mcq', 'practical'];

    /** Percentage in hundredths (80.00 = 8000) => [grade, point in hundredths]. Highest first. */
    private const SCALE = [
        [8000, 'A+', 500],
        [7000, 'A', 400],
        [6000, 'A-', 350],
        [5000, 'B', 300],
        [4000, 'C', 200],
        [3300, 'D', 100],
    ];

    /** The grade a failed unit gets. */
    public const FAIL_GRADE = 'F';

    /** A 4th subject only adds the points above this (in hundredths). */
    private const OPTIONAL_THRESHOLD = 200;

    private const MAX_GPA = 500;

    /**
     * The grade and point for a percentage, compared half-up on 2 decimals (32.995 counts
     * as 33.00, a D).
     *
     * @return array{grade: string, point: string}
     */
    public static function gradeFor(float $percentage): array
    {
        return self::gradeForHundredths((int) floor($percentage * 100 + 0.5 + 1e-9));
    }

    /**
     * Grades one unit: a single subject paper, or a pair of papers combined into one
     * subject. A unit fails (F, 0.00) when the student was absent from any paper, or when
     * any part's marks, added across the papers, are below that part's pass marks (also
     * added across the papers). Otherwise the grade comes from the combined percentage.
     *
     * A paper is `['absent' => bool, 'parts' => ['written' => ['obtained' => ?, 'full' => int, 'pass' => int], ...]]`;
     * a part the paper does not have is left out. A null `obtained` counts as 0.
     *
     * @param  list<array{absent: bool, parts: array<string, array{obtained: float|int|string|null, full: int, pass: int}>}>  $papers
     * @return array{obtained: string, full: string, percentage: string, grade: string, point: string, is_absent: bool, parts: array<string, array{obtained: string, full: int, pass: int}>}
     */
    public static function unit(array $papers): array
    {
        $absent = false;
        $parts = [];

        foreach ($papers as $paper) {
            $absent = $absent || (bool) $paper['absent'];

            foreach (self::PARTS as $part) {
                if (! isset($paper['parts'][$part])) {
                    continue;
                }

                $row = $paper['parts'][$part];
                $parts[$part] ??= ['obtained' => 0, 'full' => 0, 'pass' => 0];
                $parts[$part]['obtained'] += $paper['absent'] ? 0 : self::hundredths($row['obtained']);
                $parts[$part]['full'] += (int) $row['full'];
                $parts[$part]['pass'] += (int) $row['pass'];
            }
        }

        $obtained = array_sum(array_column($parts, 'obtained'));
        $full = array_sum(array_column($parts, 'full'));
        $failed = $absent || $full === 0;

        foreach ($parts as $part) {
            // Pass marks are whole marks; obtained is in hundredths.
            $failed = $failed || $part['obtained'] < $part['pass'] * 100;
        }

        $percentage = $full > 0 ? intdiv(2 * $obtained * 100 + $full, 2 * $full) : 0;
        $graded = $failed
            ? ['grade' => self::FAIL_GRADE, 'point' => '0.00']
            : self::gradeForHundredths($percentage);

        return [
            'obtained' => self::decimal($obtained),
            'full' => number_format($full, 2, '.', ''),
            'percentage' => self::decimal($percentage),
            ...$graded,
            'is_absent' => $absent,
            'parts' => array_map(fn (array $part) => [
                'obtained' => self::decimal($part['obtained']),
                'full' => $part['full'],
                'pass' => $part['pass'],
            ], $parts),
        ];
    }

    /**
     * A student's overall result from the graded units: the GPA, its grade and whether the
     * student passed. Each unit is `['grade', 'point', 'obtained', 'full']` as `unit()`
     * returns it.
     *
     * GPA = (sum of the compulsory points + max(0, 4th-subject point - 2)) / the number of
     * compulsory units, capped at 5.00 and rounded half-up to 2 decimals. Any failed
     * compulsory unit makes the GPA 0.00 and the grade F; a failed 4th subject never fails
     * the student. passed_count and failed_count count compulsory units only. The totals include the 4th subject. A student with no compulsory unit
     * has nothing to pass and fails.
     *
     * @param  list<array{grade: string, point: string, obtained: string, full: string}>  $compulsory
     * @param  array{grade: string, point: string, obtained: string, full: string}|null  $optional
     * @return array{gpa: string, grade: string, is_pass: bool, failed_count: int, passed_count: int, total_obtained: string, total_full: string}
     */
    public static function result(array $compulsory, ?array $optional = null): array
    {
        $units = $optional === null ? $compulsory : [...$compulsory, $optional];
        $failedCount = count(array_filter($compulsory, fn (array $unit) => $unit['grade'] === self::FAIL_GRADE));
        $count = count($compulsory);
        // Compulsory units that are not an F (a combined pair is one unit). The 4th subject is never counted, passed or failed.
        $passedCount = $count - $failedCount;

        $gpa = 0;

        if ($count > 0 && $failedCount === 0) {
            $sum = array_sum(array_map(fn (array $unit) => self::hundredths($unit['point']), $compulsory));

            if ($optional !== null) {
                $sum += max(0, self::hundredths($optional['point']) - self::OPTIONAL_THRESHOLD);
            }

            $gpa = min(self::MAX_GPA, intdiv(2 * $sum + $count, 2 * $count));
        }

        $isPass = $count > 0 && $failedCount === 0;

        return [
            'gpa' => self::decimal($gpa),
            'grade' => $isPass ? self::gradeForGpa(self::decimal($gpa)) : self::FAIL_GRADE,
            'is_pass' => $isPass,
            'failed_count' => $failedCount,
            'passed_count' => $passedCount,
            'total_obtained' => self::decimal(array_sum(array_map(fn (array $unit) => self::hundredths($unit['obtained']), $units))),
            'total_full' => self::decimal(array_sum(array_map(fn (array $unit) => self::hundredths($unit['full']), $units))),
        ];
    }

    /**
     * The overall grade for a GPA: 5.00 is A+, then A from 4.00, A- from 3.50, B from 3.00,
     * C from 2.00, D from 1.00, otherwise F.
     */
    public static function gradeForGpa(string|float|int $gpa): string
    {
        $hundredths = self::hundredths($gpa);

        if ($hundredths >= self::MAX_GPA) {
            return 'A+';
        }

        foreach (array_slice(self::SCALE, 1) as [, $grade, $point]) {
            if ($hundredths >= $point) {
                return $grade;
            }
        }

        return self::FAIL_GRADE;
    }

    /**
     * Merit positions (standard competition ranking: 1, 2, 2, 4). The higher GPA comes
     * first (a failed student's GPA is 0.00, below every passed student's), then more passed
     * subjects, then the higher total; students equal on all three share a position. The input order (roll number, say) never affects a position.
     *
     * @param  list<array{key: int|string, gpa: string, passed_count: int, total: string}>  $rows
     * @return array<int|string, int> position by key
     */
    public static function positions(array $rows): array
    {
        $sortable = array_map(fn (array $row) => [
            'key' => $row['key'],
            'tuple' => [self::hundredths($row['gpa']), (int) $row['passed_count'], self::hundredths($row['total'])],
        ], $rows);

        usort($sortable, fn (array $a, array $b) => $b['tuple'] <=> $a['tuple']);

        $positions = [];
        $previous = null;
        $position = 0;

        foreach ($sortable as $index => $row) {
            if ($row['tuple'] !== $previous) {
                $position = $index + 1;
                $previous = $row['tuple'];
            }

            $positions[$row['key']] = $position;
        }

        return $positions;
    }

    /**
     * @return array{grade: string, point: string}
     */
    private static function gradeForHundredths(int $percentage): array
    {
        foreach (self::SCALE as [$minimum, $grade, $point]) {
            if ($percentage >= $minimum) {
                return ['grade' => $grade, 'point' => self::decimal($point)];
            }
        }

        return ['grade' => self::FAIL_GRADE, 'point' => '0.00'];
    }

    /** A number or decimal string as whole hundredths ("47.50" => 4750). */
    private static function hundredths(float|int|string|null $value): int
    {
        return (int) round(((float) ($value ?? 0)) * 100);
    }

    private static function decimal(int $hundredths): string
    {
        return number_format($hundredths / 100, 2, '.', '');
    }
}
