<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\ExamSubject;
use App\Models\Section;
use App\Models\StudentEnrolment;
use App\Models\Subject;
use App\Services\EnrolmentService;
use Database\Seeders\ClassSeeder;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\SubjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\Concerns\BuildsExams;
use Tests\TestCase;

/**
 * Class 9 Science: Biology (compulsory) and Higher Math (optional) form the `science-4th`
 * choice pair, Agriculture is a plain optional. Whoever takes one of the pair as the 4th
 * subject has the other as compulsory.
 */
class CompulsoryChoiceTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private Subject $biology;

    private Subject $agriculture;

    private Exam $exam;

    private StudentEnrolment $bioFourth;

    private StudentEnrolment $hmFourth;

    private StudentEnrolment $noFourth;

    private StudentEnrolment $agrFourth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpExams();

        $this->biology = Subject::factory()->create(['name' => 'Biology', 'code' => 'BIO-T']);
        $this->agriculture = Subject::factory()->create(['name' => 'Agriculture', 'code' => 'AGR-T']);
        $theory = ['written_full' => 70, 'written_pass' => 23, 'mcq_full' => 30, 'mcq_pass' => 10];

        $this->curriculum($this->class9, $this->biology, 'science', 'compulsory', $theory + ['choice_group' => 'science-4th']);
        $this->curriculum($this->class9, $this->agriculture, 'science', 'optional', $theory);
        ClassSubject::where('class_id', $this->class9->id)->where('subject_id', $this->higherMath->id)->update(['choice_group' => 'science-4th']);

        $this->bioFourth = $this->enrol($this->section9, 'science', $this->biology, 1);
        $this->hmFourth = $this->enrol($this->section9, 'science', $this->higherMath, 2);
        $this->noFourth = $this->enrol($this->section9, 'science', null, 3);
        $this->agrFourth = $this->enrol($this->section9, 'science', $this->agriculture, 4);

        $this->exam = $this->createExam([$this->class9]);
    }

    private function row(Subject $subject): ExamSubject
    {
        return $this->exam->examSubjects()->where('subject_id', $subject->id)->firstOrFail();
    }

    private function mark(StudentEnrolment $enrolment, Subject $subject, float $written, ?float $mcq = null, ?float $practical = null): void
    {
        ExamMark::create([
            'exam_subject_id' => $this->row($subject)->id,
            'student_id' => $enrolment->student_id,
            'enrolment_id' => $enrolment->id,
            'written' => $written, 'mcq' => $mcq, 'practical' => $practical, 'is_absent' => false,
        ]);
    }

    /** Full marks in Bangla and Physics, the base every case builds on. */
    private function markCore(StudentEnrolment $enrolment): void
    {
        $this->mark($enrolment, $this->bangla, 70, 30);
        $this->mark($enrolment, $this->physics, 50, 25, 25);
    }

    private function processExam(): void
    {
        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/open-marks-entry")->assertOk();
    }

    private function process(): void
    {
        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/process")->assertOk();
    }

    private function resultOf(StudentEnrolment $enrolment): ExamResult
    {
        return ExamResult::where('exam_id', $this->exam->id)->where('enrolment_id', $enrolment->id)->firstOrFail();
    }

    /** @return array<int, bool> subject id => whether the result grades it as the 4th subject */
    private function optionalFlags(StudentEnrolment $enrolment): array
    {
        return collect($this->resultOf($enrolment)->subjects)
            ->mapWithKeys(fn (array $unit) => [$unit['subject_ids'][0] => $unit['is_optional']])
            ->all();
    }

    // ---- Curriculum validation ----------------------------------------------------------

    private function replace(Classes $class, array $rows)
    {
        return $this->as($this->admin)->putJson("/api/classes/{$class->id}/subjects", ['subjects' => $rows]);
    }

    private function line(Subject $subject, string $type, ?string $group = 'science', array $extra = []): array
    {
        return ['subject_id' => $subject->id, 'group' => $group, 'type' => $type, 'written_full' => 100, 'written_pass' => 33, ...$extra];
    }

    public function test_a_choice_pair_is_saved_and_returned(): void
    {
        $pair = 'science-4th';

        $this->replace($this->class10, [
            $this->line($this->biology, 'compulsory', 'science', ['choice_group' => $pair]),
            $this->line($this->higherMath, 'optional', 'science', ['choice_group' => $pair]),
            $this->line($this->bangla, 'compulsory', null),
        ])->assertOk()
            ->assertJsonPath('data.0.choice_group', $pair)
            ->assertJsonPath('data.1.choice_group', $pair)
            ->assertJsonPath('data.2.choice_group', null);

        $this->as($this->admin)->getJson("/api/classes/{$this->class10->id}/subjects")
            ->assertOk()->assertJsonPath('data.0.choice_group', $pair);

        // A row that omits the key keeps its saved pairing.
        $this->replace($this->class10, [
            $this->line($this->biology, 'compulsory'),
            $this->line($this->higherMath, 'optional'),
            $this->line($this->bangla, 'compulsory', null),
        ])->assertOk()->assertJsonPath('data.0.choice_group', $pair);
    }

    public function test_choice_groups_with_the_wrong_number_of_members_are_refused(): void
    {
        $this->replace($this->class10, [$this->line($this->biology, 'compulsory', 'science', ['choice_group' => 'x'])])
            ->assertUnprocessable()->assertJsonValidationErrors(['subjects.0.choice_group']);

        $this->replace($this->class10, [
            $this->line($this->biology, 'compulsory', 'science', ['choice_group' => 'x']),
            $this->line($this->higherMath, 'optional', 'science', ['choice_group' => 'x']),
            $this->line($this->agriculture, 'optional', 'science', ['choice_group' => 'x']),
        ])->assertUnprocessable()->assertJsonValidationErrors(['subjects.0.choice_group', 'subjects.1.choice_group', 'subjects.2.choice_group']);
    }

    public function test_a_choice_pair_needs_one_compulsory_and_one_optional_row(): void
    {
        $this->replace($this->class10, [
            $this->line($this->biology, 'compulsory', 'science', ['choice_group' => 'x']),
            $this->line($this->physics, 'compulsory', 'science', ['choice_group' => 'x']),
        ])->assertUnprocessable()->assertJsonValidationErrors(['subjects.0.choice_group', 'subjects.1.choice_group']);

        $this->replace($this->class10, [
            $this->line($this->higherMath, 'optional', 'science', ['choice_group' => 'x']),
            $this->line($this->agriculture, 'optional', 'science', ['choice_group' => 'x']),
        ])->assertUnprocessable()->assertJsonValidationErrors(['subjects.0.choice_group']);
    }

    public function test_a_choice_pair_must_share_one_group_and_cannot_be_class_wide(): void
    {
        $this->replace($this->class10, [
            $this->line($this->biology, 'compulsory', 'science', ['choice_group' => 'x']),
            $this->line($this->higherMath, 'optional', 'humanities', ['choice_group' => 'x']),
        ])->assertUnprocessable()->assertJsonValidationErrors(['subjects.0.choice_group', 'subjects.1.choice_group']);

        $this->replace($this->class10, [
            $this->line($this->biology, 'compulsory', null, ['choice_group' => 'x']),
            $this->line($this->higherMath, 'optional', null, ['choice_group' => 'x']),
        ])->assertUnprocessable()->assertJsonValidationErrors(['subjects.0.choice_group']);
    }

    public function test_a_choice_pair_is_refused_below_class_nine(): void
    {
        $class8 = Classes::factory()->create(['number' => 8]);

        $this->replace($class8, [
            $this->line($this->biology, 'compulsory', null, ['choice_group' => 'x']),
            $this->line($this->higherMath, 'compulsory', null, ['choice_group' => 'x']),
        ])->assertUnprocessable()->assertJsonValidationErrors(['subjects.0.choice_group', 'subjects.1.choice_group']);
    }

    public function test_a_row_cannot_be_in_both_a_paper_group_and_a_choice_group(): void
    {
        $this->replace($this->class10, [
            $this->line($this->biology, 'compulsory', 'science', ['choice_group' => 'x', 'paper_group' => 'p']),
            $this->line($this->higherMath, 'optional', 'science', ['choice_group' => 'x']),
        ])->assertUnprocessable()->assertJsonValidationErrors(['subjects.0.choice_group']);
    }

    public function test_a_bad_choice_group_slug_is_refused(): void
    {
        foreach (['Science 4th', 'science_4th', str_repeat('a', 51)] as $slug) {
            $this->replace($this->class10, [
                $this->line($this->biology, 'compulsory', 'science', ['choice_group' => $slug]),
                $this->line($this->higherMath, 'optional', 'science', ['choice_group' => $slug]),
            ])->assertUnprocessable()->assertJsonValidationErrors(['subjects.0.choice_group']);
        }
    }

    // ---- Who takes what -----------------------------------------------------------------

    public function test_the_snapshot_copies_the_choice_group_and_regenerating_refreshes_it(): void
    {
        $this->assertSame('science-4th', $this->row($this->biology)->choice_group);
        $this->assertSame('science-4th', $this->row($this->higherMath)->choice_group);
        $this->assertNull($this->row($this->agriculture)->choice_group);
        $this->assertNull($this->row($this->bangla)->choice_group);

        ClassSubject::where('class_id', $this->class9->id)->update(['choice_group' => null]);
        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/classes/{$this->class9->id}/regenerate")->assertOk();
        $this->assertNull($this->row($this->biology)->choice_group);

        ClassSubject::where('class_id', $this->class9->id)->whereIn('subject_id', [$this->biology->id, $this->higherMath->id])->update(['choice_group' => 'science-4th']);
        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/classes/{$this->class9->id}/regenerate")->assertOk();
        $this->assertSame('science-4th', $this->row($this->higherMath)->choice_group);
        $this->assertEqualsCanonicalizing([$this->biology->id, $this->higherMath->id], $this->row($this->higherMath)->choiceSubjectIds());
    }

    public function test_a_student_with_biology_as_4th_takes_both_pair_members(): void
    {
        $this->assertTrue($this->bioFourth->takes($this->row($this->biology)));
        $this->assertTrue($this->bioFourth->takes($this->row($this->higherMath)));
        $this->assertTrue($this->bioFourth->hasAsFourth($this->row($this->biology)));
        $this->assertFalse($this->bioFourth->hasAsFourth($this->row($this->higherMath)));
    }

    public function test_a_student_with_higher_math_as_4th_keeps_the_current_behaviour(): void
    {
        $this->assertTrue($this->hmFourth->takes($this->row($this->biology)));
        $this->assertTrue($this->hmFourth->takes($this->row($this->higherMath)));
        $this->assertFalse($this->hmFourth->hasAsFourth($this->row($this->biology)));
        $this->assertTrue($this->hmFourth->hasAsFourth($this->row($this->higherMath)));
    }

    public function test_without_a_pair_4th_subject_only_the_compulsory_member_applies(): void
    {
        foreach ([$this->noFourth, $this->agrFourth] as $enrolment) {
            $this->assertTrue($enrolment->takes($this->row($this->biology)));
            $this->assertFalse($enrolment->takes($this->row($this->higherMath)));
            $this->assertFalse($enrolment->hasAsFourth($this->row($this->biology)));
        }

        $this->assertTrue($this->agrFourth->takes($this->row($this->agriculture)));
        $this->assertTrue($this->agrFourth->hasAsFourth($this->row($this->agriculture)));
    }

    public function test_the_scope_and_takes_agree_for_the_pair(): void
    {
        $enrolments = StudentEnrolment::all();

        foreach ([$this->biology, $this->higherMath, $this->agriculture, $this->physics] as $subject) {
            $row = $this->row($subject);
            $viaScope = StudentEnrolment::takingSubject($row)->pluck('id')->sort()->values()->all();
            $viaModel = $enrolments->filter(fn (StudentEnrolment $e) => $e->takes($row))->pluck('id')->sort()->values()->all();

            $this->assertSame($viaModel, $viaScope, $subject->name);
        }

        $this->assertSame(
            [$this->bioFourth->id, $this->hmFourth->id],
            StudentEnrolment::takingSubject($this->row($this->higherMath))->orderBy('id')->pluck('id')->all(),
        );
    }

    // ---- Results ------------------------------------------------------------------------

    public function test_biology_as_4th_makes_higher_math_compulsory_and_a_failed_biology_does_not_fail(): void
    {
        $this->processExam();
        $this->markCore($this->bioFourth);
        $this->mark($this->bioFourth, $this->higherMath, 70, 30);
        $this->mark($this->bioFourth, $this->biology, 5, 2);
        $this->process();

        $result = $this->resultOf($this->bioFourth);

        $this->assertTrue($result->is_pass);
        $this->assertSame(0, $result->failed_count);
        $this->assertSame('5.00', $result->gpa);
        $this->assertEquals(
            [$this->bangla->id => false, $this->physics->id => false, $this->biology->id => true, $this->higherMath->id => false],
            $this->optionalFlags($this->bioFourth),
        );
    }

    public function test_failing_higher_math_fails_a_student_who_took_biology_as_4th(): void
    {
        $this->processExam();
        $this->markCore($this->bioFourth);
        $this->mark($this->bioFourth, $this->higherMath, 5, 2);
        $this->mark($this->bioFourth, $this->biology, 70, 30);
        $this->process();

        $result = $this->resultOf($this->bioFourth);

        $this->assertFalse($result->is_pass);
        $this->assertSame(1, $result->failed_count);
        $this->assertSame('F', $result->grade);
    }

    public function test_only_the_bonus_above_2_counts_for_the_4th_subject_biology(): void
    {
        $this->processExam();
        $this->markCore($this->bioFourth);
        $this->mark($this->bioFourth, $this->higherMath, 49, 21); // 70%: A, 4.00
        $this->mark($this->bioFourth, $this->biology, 28, 12); // 40%: C, 2.00, no bonus
        $this->process();

        $this->assertSame('4.67', $this->resultOf($this->bioFourth)->gpa); // (5 + 5 + 4) / 3

        // A 50% Biology (B, 3.00) adds one point of bonus: (14 + 1) / 3.
        ExamMark::where('exam_subject_id', $this->row($this->biology)->id)->update(['written' => 35, 'mcq' => 15]);
        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/reopen")->assertOk();
        $this->process();

        $this->assertSame('5.00', $this->resultOf($this->bioFourth)->gpa);
    }

    public function test_higher_math_as_4th_keeps_biology_compulsory(): void
    {
        $this->processExam();
        $this->markCore($this->hmFourth);
        $this->mark($this->hmFourth, $this->biology, 5, 2);
        $this->mark($this->hmFourth, $this->higherMath, 70, 30);
        $this->process();

        $result = $this->resultOf($this->hmFourth);

        $this->assertFalse($result->is_pass);
        $this->assertSame(1, $result->failed_count);
        $this->assertTrue($this->optionalFlags($this->hmFourth)[$this->higherMath->id]);
        $this->assertFalse($this->optionalFlags($this->hmFourth)[$this->biology->id]);
    }

    public function test_with_no_4th_subject_biology_is_compulsory_and_higher_math_is_not_taken(): void
    {
        $this->processExam();
        $this->markCore($this->noFourth);
        $this->mark($this->noFourth, $this->biology, 70, 30);
        $this->process();

        $flags = $this->optionalFlags($this->noFourth);

        $this->assertFalse($flags[$this->biology->id]);
        $this->assertArrayNotHasKey($this->higherMath->id, $flags);
        $this->assertTrue($this->resultOf($this->noFourth)->is_pass);
    }

    public function test_the_mark_sheet_lists_everyone_who_takes_the_subject(): void
    {
        $this->processExam();

        $students = fn (Subject $subject) => collect($this->as($this->admin)
            ->getJson("/api/exams/{$this->exam->id}/marks?section_id={$this->section9->id}&exam_subject_id={$this->row($subject)->id}")
            ->assertOk()->json('data.students'))->pluck('enrolment_id')->sort()->values()->all();

        // Higher Math: the two who chose Biology or Higher Math as the 4th subject.
        $this->assertSame([$this->bioFourth->id, $this->hmFourth->id], $students($this->higherMath));
        // Biology: every Science student.
        $this->assertSame(
            collect([$this->bioFourth, $this->hmFourth, $this->noFourth, $this->agrFourth])->pluck('id')->sort()->values()->all(),
            $students($this->biology),
        );
    }

    public function test_reprocessing_an_exam_applies_the_choice_to_existing_results(): void
    {
        $this->processExam();
        $this->markCore($this->bioFourth);
        $this->mark($this->bioFourth, $this->higherMath, 70, 30);
        $this->mark($this->bioFourth, $this->biology, 5, 2);
        $this->process();

        // Switch the 4th subject to Higher Math: the saved result is stale until reprocessed.
        $this->bioFourth->update(['optional_subject_id' => $this->higherMath->id]);
        $this->assertTrue($this->resultOf($this->bioFourth)->is_pass);

        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/reopen")->assertOk();
        $this->process();

        $this->assertFalse($this->resultOf($this->bioFourth)->is_pass);
        $this->assertTrue($this->optionalFlags($this->bioFourth)[$this->higherMath->id]);
    }

    // ---- Enrolment and promotion --------------------------------------------------------

    private function placementErrors(?Subject $optional): array
    {
        return app(EnrolmentService::class)->placementErrors($this->section9->load('class'), 'science', $optional?->id);
    }

    public function test_either_pair_member_is_accepted_as_the_4th_subject(): void
    {
        $this->assertSame([], $this->placementErrors($this->biology));
        $this->assertSame([], $this->placementErrors($this->higherMath));
        $this->assertSame([], $this->placementErrors($this->agriculture));
        $this->assertSame([], $this->placementErrors(null));
    }

    public function test_a_subject_outside_the_curriculum_is_still_refused_as_the_4th_subject(): void
    {
        $this->assertArrayHasKey('enrolment.optional_subject_id', $this->placementErrors($this->accounting));
        // Physics is plain compulsory, not a 4th-subject choice.
        $this->assertArrayHasKey('enrolment.optional_subject_id', $this->placementErrors($this->physics));
    }

    private function promotionSetUp(bool $withPair): Section
    {
        AcademicYear::factory()->create(['year' => 2027]);
        $theory = ['written_full' => 70, 'written_pass' => 23, 'mcq_full' => 30, 'mcq_pass' => 10];
        $this->curriculum($this->class10, $this->biology, 'science', 'compulsory', $theory + ($withPair ? ['choice_group' => 'science-4th'] : []));

        if ($withPair) {
            $this->curriculum($this->class10, $this->higherMath, 'science', 'optional', $theory + ['choice_group' => 'science-4th']);
        }

        return Section::factory()->create(['class_id' => $this->class10->id, 'shift_id' => $this->shift->id, 'code' => 'A']);
    }

    private function promote(Section $target)
    {
        return $this->as($this->admin)->postJson('/api/promotions', [
            'from_academic_year_id' => $this->year->id,
            'to_academic_year_id' => AcademicYear::where('year', 2027)->value('id'),
            'section_id' => $this->section9->id,
            'default_target_section_id' => $target->id,
            'exceptions' => [],
        ]);
    }

    public function test_promotion_carries_biology_as_4th_over_to_the_class_10_pair(): void
    {
        $target = $this->promotionSetUp(true);
        $this->noFourth->update(['status' => 'left']);
        $this->hmFourth->update(['status' => 'left']);
        $this->agrFourth->update(['status' => 'left']);

        $this->promote($target)->assertOk()->assertJsonPath('data.summary.promoted', 1);

        $new = StudentEnrolment::where('student_id', $this->bioFourth->student_id)->where('class_id', $this->class10->id)->firstOrFail();
        $this->assertSame($this->biology->id, $new->optional_subject_id);
        $this->assertSame('science', $new->group);
    }

    public function test_promotion_is_refused_when_the_target_class_has_no_pair_for_the_choice(): void
    {
        $target = $this->promotionSetUp(false);
        $this->noFourth->update(['status' => 'left']);
        $this->hmFourth->update(['status' => 'left']);
        $this->agrFourth->update(['status' => 'left']);

        $this->promote($target)->assertUnprocessable()->assertJsonValidationErrors(['optional_subject_id']);

        $this->assertSame(0, StudentEnrolment::where('class_id', $this->class10->id)->count());
    }

    // ---- Seeder and backfill ------------------------------------------------------------

    public function test_the_seeder_puts_biology_and_higher_math_in_a_choice_pair_for_classes_9_to_12(): void
    {
        ClassSubject::query()->delete();
        $this->seed([ClassSeeder::class, SubjectSeeder::class, CurriculumSeeder::class]);

        foreach ([9, 10, 11, 12] as $number) {
            $class = Classes::where('number', $number)->whereHas('curriculum')->firstOrFail();
            $rows = $class->curriculum()->whereNotNull('choice_group')->with('subject')->get();

            $this->assertSame(['BIO', 'HMATH'], $rows->pluck('subject.code')->sort()->values()->all(), "Class {$number}");
            $this->assertSame(['science'], $rows->pluck('group')->unique()->values()->all());
            $this->assertSame('compulsory', $rows->firstWhere('subject.code', 'BIO')->type);
            $this->assertSame('optional', $rows->firstWhere('subject.code', 'HMATH')->type);
            $this->assertSame('optional', $class->curriculum()->whereHas('subject', fn ($q) => $q->where('code', 'AGR'))->where('group', 'science')->value('type'));
            $this->assertNull($class->curriculum()->whereHas('subject', fn ($q) => $q->where('code', 'AGR'))->value('choice_group'));
        }
    }

    private function backfill(): void
    {
        $migration = require database_path('migrations/2026_10_12_000001_add_choice_group_to_curriculum.php');
        (new ReflectionMethod($migration, 'backfill'))->invoke($migration);
    }

    public function test_the_backfill_pairs_only_the_exact_biology_and_higher_math_science_rows_and_is_idempotent(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('class_subjects', 'choice_group'));

        $hmath = Subject::factory()->create(['code' => 'HMATH']);
        $bio = Subject::factory()->create(['code' => 'BIO']);
        $classes = collect([9, 10, 11, 12, 8])->mapWithKeys(fn ($n) => [$n => Classes::where('number', $n)->first() ?? Classes::factory()->create(['number' => $n])]);

        // Class 9: the exact pair. Class 10: Biology optional (not the exact pair). Class 11: both optional.
        // Class 12: the pair in Humanities. Class 8: below Class 9.
        $this->curriculum($classes[9], $bio, 'science', 'compulsory');
        $this->curriculum($classes[9], $hmath, 'science', 'optional');
        $this->curriculum($classes[10], $bio, 'science', 'optional');
        $this->curriculum($classes[10], $hmath, 'science', 'optional');
        $this->curriculum($classes[11], $hmath, 'science', 'optional');
        $this->curriculum($classes[12], $bio, 'humanities', 'compulsory');
        $this->curriculum($classes[12], $hmath, 'humanities', 'optional');
        $this->curriculum($classes[8], $bio, null, 'compulsory');
        $this->curriculum($classes[9], $this->agriculture, 'science', 'optional');

        $this->artisan('migrate')->assertSuccessful();

        $paired = fn () => DB::table('class_subjects')->whereNotNull('choice_group')->orderBy('id')->get(['class_id', 'subject_id', 'choice_group'])
            ->map(fn ($r) => [$r->class_id, $r->subject_id, $r->choice_group])->all();

        $expected = [[$classes[9]->id, $bio->id, 'science-4th'], [$classes[9]->id, $hmath->id, 'science-4th']];
        $this->assertSame($expected, $paired());

        $this->backfill();
        $this->assertSame($expected, $paired());
    }

    public function test_the_migration_rolls_back_and_runs_again(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('class_subjects', 'choice_group'));
        $this->assertFalse(Schema::hasColumn('exam_subjects', 'choice_group'));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('class_subjects', 'choice_group'));
        $this->assertTrue(Schema::hasColumn('exam_subjects', 'choice_group'));
    }
}
