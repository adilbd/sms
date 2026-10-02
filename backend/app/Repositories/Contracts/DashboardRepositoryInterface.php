<?php

namespace App\Repositories\Contracts;

use App\Models\Exam;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Read-only aggregate queries for the dashboard. Every method is one count / sum /
 * group-by query (plus a fixed number of eager loads), so the number of queries never
 * depends on how many students or sections the school has. Amounts come back as raw
 * database values (string, int or float, depending on the driver) for App\Support\Money.
 * A null `$sectionIds` means "every section"; an empty list means none.
 */
interface DashboardRepositoryInterface
{
    /**
     * Active enrolments of the year counted per (section, group, 4th subject): rows of
     * `section_id`, `group`, `optional_subject_id`, `students`. The one source for student
     * counts and for how many students take an exam subject.
     *
     * @param  list<int>|null  $sectionIds
     * @return SupportCollection<int, object>
     */
    public function enrolmentGroups(int $academicYearId, ?array $sectionIds): SupportCollection;

    /**
     * Sections (not deleted, active or not) with their class and shift loaded, and their
     * main class teacher for $academicYearId (`mainClassSections.staff`).
     *
     * @param  list<int>|null  $sectionIds
     */
    public function sections(?array $sectionIds, int $academicYearId): Collection;

    /**
     * Active staff counted: `total`, `teachers`, `with_login`.
     *
     * @return array{total: int, teachers: int, with_login: int}
     */
    public function staffCounts(): array;

    /**
     * The attendance rows of one date counted per (section, status): `section_id`,
     * `status`, `total`.
     *
     * @param  list<int>|null  $sectionIds
     * @return SupportCollection<int, object>
     */
    public function attendanceCounts(int $academicYearId, string $date, ?array $sectionIds): SupportCollection;

    /**
     * The latest exam of the year that is not a draft, or null.
     */
    public function latestExam(int $academicYearId): ?Exam;

    /**
     * The exam's results per class: `class_id`, `class_number`, `class_name`, `students`,
     * `passed`, `top_gpa`.
     *
     * @return SupportCollection<int, object>
     */
    public function resultsByClass(int $examId): SupportCollection;

    /**
     * The exam's results per section: `section_id`, `students`, `passed`.
     *
     * @param  list<int>  $sectionIds
     * @return SupportCollection<int, object>
     */
    public function resultsBySection(int $examId, array $sectionIds): SupportCollection;

    /**
     * The scheduled subjects (with exam and subject loaded) of the year's exams that are
     * in marks entry.
     */
    public function openExamSubjects(int $academicYearId): Collection;

    /**
     * Mark rows entered per (exam subject, section): `exam_subject_id`, `section_id`,
     * `entered`.
     *
     * @param  list<int>  $examSubjectIds
     * @param  list<int>|null  $sectionIds
     * @return SupportCollection<int, object>
     */
    public function enteredMarkCounts(array $examSubjectIds, ?array $sectionIds): SupportCollection;

    /**
     * The year's most recently processed or published exams, newest first.
     */
    public function recentExams(int $academicYearId, int $limit): Collection;

    /**
     * Net and paid totals of the dues falling due from $from to $to (`Y-m-d`, inclusive)
     * under the year's enrolments: `net`, `paid`.
     *
     * @return array{net: mixed, paid: mixed}
     */
    public function dueTotals(int $academicYearId, string $from, string $to): array;

    /**
     * Dues due before $today that are not fully paid: `count` and `outstanding`.
     *
     * @return array{count: int, outstanding: mixed}
     */
    public function overdueTotals(int $academicYearId, string $today): array;

    /**
     * How many distinct students still owe something on the year's dues.
     */
    public function studentsWithOutstandingDues(int $academicYearId): int;

    /**
     * Payments received in [$from, $to) that are not cancelled, per method: `method`,
     * `payments`, `total`.
     *
     * @return SupportCollection<int, object>
     */
    public function collectionByMethod(CarbonInterface $from, CarbonInterface $to): SupportCollection;

    /**
     * The newest payments that are not cancelled (with their student), newest first.
     */
    public function recentPayments(int $limit): Collection;

    /**
     * The students admitted last (with their enrolment in the year, class and section).
     */
    public function recentStudents(int $academicYearId, int $limit): Collection;
}
