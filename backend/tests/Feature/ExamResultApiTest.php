<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\ExamSubject;
use App\Models\Section;
use App\Models\StudentEnrolment;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsExams;
use Tests\TestCase;

/**
 * Class 9 has a paired Bangla (two papers, 70 written + 30 MCQ each), Physics for Science,
 * Accounting for Business Studies and Higher Math as Science's 4th subject. Section A holds
 * three students, Section B one, and Class 10 (Bangla alone) one.
 */
class ExamResultApiTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private Exam $exam;

    private Section $sectionB;

    private Section $section10;

    private Subject $bangla2;

    /** Science, Higher Math as the 4th subject. */
    private StudentEnrolment $sciHm;

    /** Science, no 4th subject. */
    private StudentEnrolment $sci;

    /** Business Studies with no marks entered at all. */
    private StudentEnrolment $biz;

    /** Section B, full marks everywhere. */
    private StudentEnrolment $sciB;

    private StudentEnrolment $ten;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpExams();

        $this->bangla2 = Subject::factory()->create(['name' => 'Bangla 2nd', 'name_bn' => 'বাংলা ২য়']);
        $this->curriculum($this->class9, $this->bangla2, null, 'compulsory', [
            'written_full' => 70, 'written_pass' => 23, 'mcq_full' => 30, 'mcq_pass' => 10, 'paper_group' => 'bangla',
        ]);

        $this->sectionB = Section::factory()->create(['class_id' => $this->class9->id, 'shift_id' => $this->shift->id, 'code' => 'B', 'name' => 'B']);
        $this->section10 = Section::factory()->create(['class_id' => $this->class10->id, 'shift_id' => $this->shift->id]);

        $this->sciHm = $this->enrol($this->section9, 'science', $this->higherMath, 1, ['name_en' => 'Sci Hm', 'name_bn' => 'বিজ্ঞান']);
        $this->sci = $this->enrol($this->section9, 'science', null, 2, ['name_en' => 'Sci Plain']);
        $this->biz = $this->enrol($this->section9, 'business_studies', null, 3, ['name_en' => 'Business']);
        $this->sciB = $this->enrol($this->sectionB, 'science', null, 1, ['name_en' => 'Sci B']);
        $this->ten = $this->enrol($this->section10, null, null, 1, ['name_en' => 'Ten']);

        $this->exam = $this->createExam();
        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/open-marks-entry")->assertOk();
    }

    private function row(Subject $subject, ?int $classId = null): ExamSubject
    {
        return $this->exam->examSubjects()->where('subject_id', $subject->id)->where('class_id', $classId ?? $this->class9->id)->firstOrFail();
    }

    private function mark(StudentEnrolment $enrolment, Subject $subject, ?float $written, ?float $mcq = null, ?float $practical = null, bool $absent = false): void
    {
        ExamMark::create([
            'exam_subject_id' => $this->row($subject, $enrolment->class_id)->id,
            'student_id' => $enrolment->student_id,
            'enrolment_id' => $enrolment->id,
            'written' => $written, 'mcq' => $mcq, 'practical' => $practical, 'is_absent' => $absent,
        ]);
    }

    /**
     * Sci Hm: Bangla 85 + 70 = 155/200 (77.5%, A, 4.00), Physics 89 (A+, 5.00), Higher Math
     * 42 (C, 2.00, so no bonus): GPA 4.50, total 286.
     * Sci: Bangla 155/200 (A), Physics 80 (A+): GPA 4.50, total 235.
     * Sci B: full marks everywhere: GPA 5.00, total 300.
     * Biz: nothing entered: F. Ten: Bangla 100 (A+).
     */
    private function enterMarks(): void
    {
        $this->mark($this->sciHm, $this->bangla, 60, 25);
        $this->mark($this->sciHm, $this->bangla2, 50, 20);
        $this->mark($this->sciHm, $this->physics, 45, 22, 22);
        $this->mark($this->sciHm, $this->higherMath, 30, 12);

        $this->mark($this->sci, $this->bangla, 60, 25);
        $this->mark($this->sci, $this->bangla2, 50, 20);
        $this->mark($this->sci, $this->physics, 40, 20, 20);

        $this->mark($this->sciB, $this->bangla, 70, 30);
        $this->mark($this->sciB, $this->bangla2, 70, 30);
        $this->mark($this->sciB, $this->physics, 50, 25, 25);

        $this->mark($this->ten, $this->bangla, 70, 30);
    }

    private function process()
    {
        return $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/process");
    }

    private function processAndPublish(): void
    {
        $this->enterMarks();
        $this->process()->assertOk();
        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/publish")->assertOk();
    }

    private function resultOf(StudentEnrolment $enrolment): ExamResult
    {
        return ExamResult::where('exam_id', $this->exam->id)->where('enrolment_id', $enrolment->id)->firstOrFail();
    }

    /** A student login linked to the enrolment's student. */
    private function studentLogin(StudentEnrolment $enrolment): User
    {
        $user = $this->userWithRole('student');
        $enrolment->student->update(['user_id' => $user->id]);

        return $user;
    }

    private function guardianOf(StudentEnrolment ...$enrolments): User
    {
        $guardian = $this->userWithRole('parent');

        foreach ($enrolments as $enrolment) {
            $enrolment->student->update(['guardian_user_id' => $guardian->id]);
        }

        return $guardian;
    }

    // Processing

    public function test_processing_writes_a_result_for_every_enrolment_and_returns_the_summary(): void
    {
        $this->enterMarks();

        $this->process()
            ->assertOk()
            ->assertJsonPath('data.exam.status', 'processed')
            ->assertJsonPath('data.exam.id', $this->exam->id)
            ->assertJsonPath('message', 'Results processed successfully')
            ->assertJsonStructure(['data' => ['exam' => ['id', 'status'], 'summary' => [[
                'class_id', 'class_name', 'section_id', 'section_name', 'students', 'passed', 'failed', 'missing_marks',
            ]]]]);

        $this->assertSame(5, ExamResult::where('exam_id', $this->exam->id)->count());
        $this->assertSame(Exam::STATUS_PROCESSED, $this->exam->fresh()->status);

        $summary = collect($this->process()->json('data.summary'))->keyBy('section_id');

        // Section A: Business has no marks (Bangla twice and Accounting are missing).
        $this->assertSame(
            ['students' => 3, 'passed' => 2, 'failed' => 1, 'missing_marks' => 3],
            collect($summary[$this->section9->id])->only(['students', 'passed', 'failed', 'missing_marks'])->all()
        );
        $this->assertSame(0, $summary[$this->sectionB->id]['missing_marks']);
        $this->assertSame(
            ['students' => 1, 'passed' => 1, 'failed' => 0, 'missing_marks' => 0],
            collect($summary[$this->section10->id])->only(['students', 'passed', 'failed', 'missing_marks'])->all()
        );
    }

    public function test_the_breakdown_has_combined_pairs_the_4th_subject_and_the_gpa(): void
    {
        $this->enterMarks();
        $this->process()->assertOk();

        $data = $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results/{$this->sciHm->student_id}")
            ->assertOk()
            ->assertJsonPath('data.gpa', '4.50')
            ->assertJsonPath('data.grade', 'A')
            ->assertJsonPath('data.is_pass', true)
            ->assertJsonPath('data.failed_count', 0)
            ->assertJsonPath('data.total_obtained', '286.00')
            ->assertJsonPath('data.total_full', '400.00')
            ->assertJsonPath('data.class_position', 2)
            ->assertJsonPath('data.section_position', 1)
            ->assertJsonPath('data.student.name_bn', 'বিজ্ঞান')
            ->assertJsonPath('data.roll_number', 1)
            ->assertJsonPath('data.group', 'science')
            ->assertJsonPath('data.class.number', 9)
            ->assertJsonPath('data.section.shift.id', $this->shift->id)
            ->assertJsonPath('data.exam.academic_year.year', 2026)
            ->json('data');

        $units = collect($data['subjects']);
        $this->assertCount(3, $units);

        $pair = $units[0];
        $this->assertTrue($pair['is_combined']);
        $this->assertFalse($pair['is_optional']);
        $this->assertSame([$this->bangla->id, $this->bangla2->id], $pair['subject_ids']);
        $this->assertSame('155.00', $pair['obtained']);
        $this->assertSame('200.00', $pair['full']);
        $this->assertSame('77.50', $pair['percentage']);
        $this->assertSame(['A', '4.00'], [$pair['grade'], $pair['point']]);
        $this->assertSame(['obtained' => '110.00', 'full' => 140, 'pass' => 46], $pair['parts']['written']);
        $this->assertCount(2, $pair['papers']);

        $this->assertSame(['A+', '5.00', false], [$units[1]['grade'], $units[1]['point'], $units[1]['is_optional']]);
        $this->assertSame(['obtained' => '45.00', 'full' => 50, 'pass' => 17], $units[1]['parts']['written']);

        $fourth = $units[2];
        $this->assertTrue($fourth['is_optional']);
        $this->assertSame([$this->higherMath->id], $fourth['subject_ids']);
        $this->assertSame(['C', '2.00', '42.00'], [$fourth['grade'], $fourth['point'], $fourth['obtained']]);
    }

    public function test_a_student_with_no_marks_gets_an_f_with_every_compulsory_unit_failed(): void
    {
        $this->enterMarks();
        $this->process()->assertOk();

        // Bangla (one pair) and Accounting: two units.
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results/{$this->biz->student_id}")
            ->assertOk()
            ->assertJsonPath('data.gpa', '0.00')
            ->assertJsonPath('data.grade', 'F')
            ->assertJsonPath('data.is_pass', false)
            ->assertJsonPath('data.failed_count', 2)
            ->assertJsonPath('data.total_obtained', '0.00')
            ->assertJsonPath('data.subjects.0.is_absent', true)
            ->assertJsonPath('data.subjects.0.papers.0.is_missing', true);
    }

    public function test_a_failed_4th_subject_does_not_fail_the_student(): void
    {
        $this->mark($this->sciHm, $this->bangla, 60, 25);
        $this->mark($this->sciHm, $this->bangla2, 50, 20);
        $this->mark($this->sciHm, $this->physics, 45, 22, 22);
        $this->mark($this->sciHm, $this->higherMath, null, null, null, absent: true);
        $this->process()->assertOk();

        // (4.00 + 5.00) / 2 = 4.50; the absent 4th subject adds nothing and fails nothing.
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results/{$this->sciHm->student_id}")
            ->assertOk()
            ->assertJsonPath('data.gpa', '4.50')
            ->assertJsonPath('data.is_pass', true)
            ->assertJsonPath('data.failed_count', 0)
            ->assertJsonPath('data.subjects.2.grade', 'F');
    }

    public function test_a_4th_subject_above_a_c_adds_the_bonus_and_the_gpa_is_capped(): void
    {
        $this->mark($this->sciHm, $this->bangla, 70, 30);
        $this->mark($this->sciHm, $this->bangla2, 70, 30);
        $this->mark($this->sciHm, $this->physics, 50, 25, 25);
        $this->mark($this->sciHm, $this->higherMath, 60, 25); // 85%: A+, (5 - 2) / 2 = 1.5 on top of 5.00.
        $this->process()->assertOk();

        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results/{$this->sciHm->student_id}")
            ->assertJsonPath('data.gpa', '5.00')
            ->assertJsonPath('data.grade', 'A+');
    }

    public function test_a_part_below_its_pass_mark_fails_the_unit_and_the_student(): void
    {
        $this->mark($this->sci, $this->bangla, 60, 25);
        $this->mark($this->sci, $this->bangla2, 50, 20);
        // 74 of 100, but practical 7 is below its pass mark of 8.
        $this->mark($this->sci, $this->physics, 45, 22, 7);
        $this->process()->assertOk();

        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results/{$this->sci->student_id}")
            ->assertJsonPath('data.gpa', '0.00')
            ->assertJsonPath('data.grade', 'F')
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonPath('data.subjects.1.grade', 'F')
            ->assertJsonPath('data.subjects.1.percentage', '74.00');
    }

    public function test_a_pair_passes_on_combined_marks_even_when_one_paper_is_below_its_pass_mark(): void
    {
        // Paper 1 written 20 (pass 23) and paper 2 written 50: combined 70 against 46.
        $this->mark($this->sci, $this->bangla, 20, 25);
        $this->mark($this->sci, $this->bangla2, 50, 20);
        $this->mark($this->sci, $this->physics, 40, 20, 20);
        $this->process()->assertOk();

        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results/{$this->sci->student_id}")
            ->assertJsonPath('data.is_pass', true)
            ->assertJsonPath('data.subjects.0.grade', 'B')
            ->assertJsonPath('data.subjects.0.percentage', '57.50');
    }

    public function test_a_partly_entered_paper_counts_as_missing(): void
    {
        $this->mark($this->sci, $this->bangla, 60, null);

        $summary = collect($this->process()->json('data.summary'))->firstWhere('section_id', $this->section9->id);

        // Science sci: Bangla 1 incomplete, Bangla 2 and Physics missing; sciHm: 4 missing
        // (two Bangla, Physics, Higher Math); biz: Bangla twice and Accounting.
        $this->assertSame(3 + 4 + 3, $summary['missing_marks']);
    }

    public function test_reprocessing_replaces_the_results_without_duplicates(): void
    {
        $this->enterMarks();
        $this->process()->assertOk();
        $this->assertSame('0.00', $this->resultOf($this->biz)->gpa);

        $this->mark($this->biz, $this->bangla, 70, 30);
        $this->mark($this->biz, $this->bangla2, 70, 30);
        $this->mark($this->biz, $this->accounting, 70, 30);
        $this->process()->assertOk();

        $this->assertSame(5, ExamResult::where('exam_id', $this->exam->id)->count());
        $this->assertSame('5.00', $this->resultOf($this->biz)->gpa);
        $this->assertTrue($this->resultOf($this->biz)->is_pass);
    }

    public function test_positions_are_ranked_within_the_class_and_within_the_section(): void
    {
        $this->enterMarks();
        $this->process()->assertOk();

        // Class 9: Sci B (5.00), Sci Hm (4.50, 286), Sci (4.50, 235), then the failed Business.
        $this->assertSame([1, 1], [$this->resultOf($this->sciB)->class_position, $this->resultOf($this->sciB)->section_position]);
        $this->assertSame([2, 1], [$this->resultOf($this->sciHm)->class_position, $this->resultOf($this->sciHm)->section_position]);
        $this->assertSame([3, 2], [$this->resultOf($this->sci)->class_position, $this->resultOf($this->sci)->section_position]);
        $this->assertSame([4, 3], [$this->resultOf($this->biz)->class_position, $this->resultOf($this->biz)->section_position]);
        // Class 10 is ranked on its own.
        $this->assertSame([1, 1], [$this->resultOf($this->ten)->class_position, $this->resultOf($this->ten)->section_position]);
    }

    public function test_only_active_enrolments_of_the_exams_year_are_processed(): void
    {
        $this->enterMarks();
        $this->sci->update(['status' => StudentEnrolment::STATUS_LEFT]);
        $this->biz->student->delete();

        $this->process()->assertOk();

        $this->assertSame(3, ExamResult::where('exam_id', $this->exam->id)->count());
        $this->assertDatabaseMissing('exam_results', ['enrolment_id' => $this->sci->id]);
        $this->assertDatabaseMissing('exam_results', ['enrolment_id' => $this->biz->id]);
    }

    // Status transitions

    public function test_the_status_moves_through_process_publish_and_unpublish(): void
    {
        $this->enterMarks();
        $this->process()->assertOk();

        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('message', 'Results published');
        $this->assertNotNull($this->exam->fresh()->published_at);

        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/unpublish")
            ->assertOk()
            ->assertJsonPath('data.status', 'processed')
            ->assertJsonPath('data.published_at', null);

        // Processed exams can be processed again.
        $this->process()->assertOk()->assertJsonPath('data.exam.status', 'processed');
    }

    public function test_processing_a_published_exam_is_a_conflict(): void
    {
        $this->processAndPublish();

        $this->process()->assertStatus(409);
        $this->assertSame(Exam::STATUS_PUBLISHED, $this->exam->fresh()->status);
    }

    public function test_publishing_needs_a_processed_exam(): void
    {
        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/publish")->assertStatus(409);

        $draft = Exam::factory()->create(['academic_year_id' => $this->year->id]);
        $this->as($this->admin)->postJson("/api/exams/{$draft->id}/publish")->assertStatus(409);
        $this->as($this->admin)->postJson("/api/exams/{$draft->id}/process")->assertStatus(409);

        $this->enterMarks();
        $this->process()->assertOk();
        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/publish")->assertOk();
        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/publish")->assertStatus(409);
    }

    public function test_unpublishing_needs_a_published_exam(): void
    {
        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/unpublish")->assertStatus(409);
    }

    public function test_marks_cannot_be_saved_while_published(): void
    {
        $this->processAndPublish();

        $this->as($this->admin)->putJson("/api/exams/{$this->exam->id}/marks", [
            'section_id' => $this->section9->id,
            'exam_subject_id' => $this->row($this->physics)->id,
            'marks' => [['student_id' => $this->sci->student_id, 'written' => 10]],
        ])->assertStatus(409);
    }

    public function test_saving_marks_while_processed_reopens_mark_entry_until_reprocessed(): void
    {
        $this->enterMarks();
        $this->process()->assertOk();

        $this->as($this->admin)->putJson("/api/exams/{$this->exam->id}/marks", [
            'section_id' => $this->section9->id,
            'exam_subject_id' => $this->row($this->physics)->id,
            'marks' => [['student_id' => $this->sci->student_id, 'written' => 50, 'mcq' => 25, 'practical' => 25]],
        ])->assertOk()->assertJsonPath('data.exam_status', 'marks_entry');

        $this->assertSame(Exam::STATUS_MARKS_ENTRY, $this->exam->fresh()->status);
        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/publish")->assertStatus(409);

        $this->process()->assertOk();
        $this->assertSame('5.00', $this->resultOf($this->sci)->subjects[1]['point']);
        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/publish")->assertOk();
    }

    // Tabulation and breakdown

    public function test_the_tabulation_is_ordered_by_position_and_paginated(): void
    {
        $this->enterMarks();
        $this->process()->assertOk();

        $response = $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results?class_id={$this->class9->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'student_id', 'student' => ['id', 'student_code', 'name_en', 'name_bn'], 'roll_number', 'group', 'gpa', 'grade', 'is_pass', 'failed_count', 'class_position', 'section_position', 'total_obtained']],
                'links',
                'meta' => ['total', 'per_page', 'current_page'],
            ])
            ->assertJsonPath('meta.total', 4);

        $this->assertSame(
            [$this->sciB->student_id, $this->sciHm->student_id, $this->sci->student_id, $this->biz->student_id],
            array_column($response->json('data'), 'student_id')
        );
        // The tabulation leaves the heavy breakdown out.
        $this->assertArrayNotHasKey('subjects', $response->json('data.0'));

        // The breakdown is opt-in, for the report cards.
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results?section_id={$this->section9->id}&with_subjects=1")
            ->assertOk()
            ->assertJsonCount(3, 'data.0.subjects');

        $section = $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results?section_id={$this->section9->id}")->assertOk();
        $this->assertSame(
            [$this->sciHm->student_id, $this->sci->student_id, $this->biz->student_id],
            array_column($section->json('data'), 'student_id')
        );

        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results?per_page=2&page=2")
            ->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonCount(2, 'data');
    }

    public function test_the_tabulation_is_empty_before_processing_and_validates_filters(): void
    {
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results")->assertOk()->assertJsonPath('meta.total', 0);
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results?class_id=abc")->assertUnprocessable()->assertJsonValidationErrors('class_id');
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results?section_id[]=1")->assertUnprocessable();
    }

    public function test_not_found_cases(): void
    {
        $this->as($this->admin)->getJson('/api/exams/1abc/results')->assertNotFound();
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results/1abc")->assertNotFound();
        $this->as($this->admin)->getJson('/api/exams/9999/results')->assertNotFound();
        $this->as($this->admin)->postJson('/api/exams/1abc/process')->assertNotFound();

        // A student with no result (nothing processed yet, or not in the exam).
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results/{$this->sci->student_id}")
            ->assertNotFound()
            ->assertJson(['message' => 'Record not found.']);
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results/999999")->assertNotFound();
    }

    // Own records

    public function test_a_student_sees_their_own_published_result_only(): void
    {
        $this->processAndPublish();
        $login = $this->studentLogin($this->sciHm);
        $this->studentLogin($this->sci);

        $response = $this->as($login)->getJson('/api/my/results')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $this->sciHm->student_id)
            ->assertJsonPath('data.0.gpa', '4.50')
            ->assertJsonPath('data.0.exam.id', $this->exam->id)
            ->assertJsonPath('data.0.exam.status', 'published');

        // The full breakdown is included.
        $this->assertCount(3, $response->json('data.0.subjects'));
    }

    public function test_unpublished_results_are_hidden_from_students_and_guardians(): void
    {
        $this->enterMarks();
        $this->process()->assertOk();
        $login = $this->studentLogin($this->sciHm);
        $guardian = $this->guardianOf($this->sci);

        $this->as($login)->getJson('/api/my/results')->assertOk()->assertJsonCount(0, 'data');
        $this->as($guardian)->getJson("/api/my/children/{$this->sci->student_id}/results")->assertOk()->assertJsonCount(0, 'data');

        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/publish")->assertOk();
        $this->as($login)->getJson('/api/my/results')->assertJsonCount(1, 'data');

        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/unpublish")->assertOk();
        $this->as($login)->getJson('/api/my/results')->assertJsonCount(0, 'data');
    }

    public function test_a_guardian_sees_each_childs_result_but_not_another_familys(): void
    {
        $this->processAndPublish();
        $guardian = $this->guardianOf($this->sciHm, $this->sciB);
        $this->guardianOf($this->sci);

        $this->as($guardian)->getJson("/api/my/children/{$this->sciHm->student_id}/results")
            ->assertOk()
            ->assertJsonPath('data.0.gpa', '4.50')
            ->assertJsonCount(3, 'data.0.subjects');
        $this->as($guardian)->getJson("/api/my/children/{$this->sciB->student_id}/results")
            ->assertOk()
            ->assertJsonPath('data.0.gpa', '5.00');

        $this->as($guardian)->getJson("/api/my/children/{$this->sci->student_id}/results")->assertForbidden();
        // An id that doesn't exist looks the same as someone else's child.
        $this->as($guardian)->getJson('/api/my/children/999999/results')->assertForbidden();
        $this->as($guardian)->getJson('/api/my/children/1abc/results')->assertNotFound();
    }

    public function test_my_exams_lists_the_schedule_of_the_callers_class_without_marks(): void
    {
        $this->row($this->physics)->update(['exam_date' => '2026-06-03', 'start_time' => '10:00', 'end_time' => '13:00']);
        $login = $this->studentLogin($this->sci);
        $guardian = $this->guardianOf($this->biz, $this->ten);

        $mine = $this->as($login)->getJson('/api/my/exams')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_id', $this->sci->student_id)
            ->assertJsonPath('data.0.exams.0.id', $this->exam->id)
            ->assertJsonPath('data.0.exams.0.status', 'marks_entry');

        // Science takes Bangla (two papers) and Physics, not Accounting or Higher Math.
        $subjects = $mine->json('data.0.exams.0.subjects');
        $this->assertEqualsCanonicalizing(['Bangla', 'Bangla 2nd', 'Physics'], array_column($subjects, 'name_en'));
        $physics = collect($subjects)->firstWhere('name_en', 'Physics');
        $this->assertSame(['subject_id', 'name_en', 'name_bn', 'exam_date', 'start_time', 'end_time'], array_keys($physics));
        $this->assertSame(['2026-06-03', '10:00', '13:00'], [$physics['exam_date'], $physics['start_time'], $physics['end_time']]);

        // A guardian gets one entry per child, each with their own class's subjects.
        $children = $this->as($guardian)->getJson('/api/my/exams')->assertOk()->assertJsonCount(2, 'data');
        $byStudent = collect($children->json('data'))->keyBy('student_id');
        $this->assertContains('Accounting', array_column($byStudent[$this->biz->student_id]['exams'][0]['subjects'], 'name_en'));
        $this->assertSame(['Bangla'], array_column($byStudent[$this->ten->student_id]['exams'][0]['subjects'], 'name_en'));
    }

    public function test_my_exams_does_not_query_per_subject_or_per_child(): void
    {
        $guardian = $this->guardianOf($this->biz, $this->ten);

        DB::enableQueryLog();
        $this->as($guardian)->getJson('/api/my/exams')->assertOk()->assertJsonCount(2, 'data');
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        // "Does this student take the subject?" is decided in memory, not by an exists() each.
        $perSubject = array_filter($queries, fn (string $sql) => str_contains($sql, 'student_enrolments') && str_contains($sql, 'exists'));
        $this->assertSame([], array_values($perSubject));
        $this->assertLessThan(25, count($queries));
    }

    public function test_the_4th_subject_and_group_rule_matches_between_the_scope_and_the_enrolment(): void
    {
        foreach ($this->exam->examSubjects()->with('subject')->get() as $subject) {
            foreach ([$this->sciHm, $this->sci, $this->biz, $this->sciB, $this->ten] as $enrolment) {
                $viaScope = StudentEnrolment::query()->whereKey($enrolment->id)->takingSubject($subject)->exists();
                $this->assertSame($viaScope, $enrolment->fresh()->takes($subject));
            }
        }
    }

    public function test_the_tabulation_per_page_is_bounded(): void
    {
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results?per_page=0")
            ->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results?per_page=101")
            ->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/results?per_page=100")->assertOk();
    }

    public function test_my_exams_hides_draft_exams(): void
    {
        $draft = Exam::factory()->create(['academic_year_id' => $this->year->id]);
        ExamSubject::factory()->create(['exam_id' => $draft->id, 'class_id' => $this->class10->id, 'subject_id' => $this->bangla->id]);
        $this->exam->fresh()->update(['status' => Exam::STATUS_DRAFT]);

        $this->as($this->studentLogin($this->ten))->getJson('/api/my/exams')
            ->assertOk()
            ->assertJsonPath('data.0.exams', []);
    }

    // Authorization

    public function test_guests_get_401(): void
    {
        $id = $this->exam->id;
        // setUp acted as the admin; drop that login.
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/exams/{$id}/results")->assertUnauthorized();
        $this->getJson("/api/exams/{$id}/results/1")->assertUnauthorized();
        $this->postJson("/api/exams/{$id}/process")->assertUnauthorized();
        $this->postJson("/api/exams/{$id}/publish")->assertUnauthorized();
        $this->postJson("/api/exams/{$id}/unpublish")->assertUnauthorized();
        $this->getJson('/api/my/results')->assertUnauthorized();
        $this->getJson('/api/my/exams')->assertUnauthorized();
        $this->getJson('/api/my/children/1/results')->assertUnauthorized();
    }

    public function test_a_teacher_reads_the_tabulation_but_cannot_process_or_publish(): void
    {
        $this->enterMarks();
        $this->process()->assertOk();
        $teacher = $this->userWithRole('teacher');
        $id = $this->exam->id;

        $this->as($teacher)->getJson("/api/exams/{$id}/results")->assertOk();
        $this->as($teacher)->getJson("/api/exams/{$id}/results/{$this->sciHm->student_id}")->assertOk();
        $this->as($teacher)->postJson("/api/exams/{$id}/process")->assertForbidden();
        $this->as($teacher)->postJson("/api/exams/{$id}/publish")->assertForbidden();
        $this->as($teacher)->postJson("/api/exams/{$id}/unpublish")->assertForbidden();
        $this->assertSame(Exam::STATUS_PROCESSED, $this->exam->fresh()->status);
    }

    public function test_students_and_guardians_cannot_use_the_admin_results_endpoints(): void
    {
        $this->processAndPublish();
        $student = $this->studentLogin($this->sciHm);
        $parent = $this->guardianOf($this->sciHm);
        $id = $this->exam->id;

        foreach ([$student, $parent] as $user) {
            $this->as($user)->getJson("/api/exams/{$id}/results")->assertForbidden();
            $this->as($user)->getJson("/api/exams/{$id}/results/{$this->sciHm->student_id}")->assertForbidden();
            $this->as($user)->postJson("/api/exams/{$id}/process")->assertForbidden();
        }
    }

    public function test_the_my_endpoints_are_role_scoped(): void
    {
        $student = $this->studentLogin($this->sciHm);
        $parent = $this->guardianOf($this->sciHm);
        $teacher = $this->userWithRole('teacher');

        $this->as($student)->getJson("/api/my/children/{$this->sciHm->student_id}/results")->assertForbidden();
        $this->as($parent)->getJson('/api/my/results')->assertForbidden();
        $this->as($teacher)->getJson('/api/my/results')->assertForbidden();
        $this->as($teacher)->getJson('/api/my/exams')->assertForbidden();
        $this->as($this->admin)->getJson('/api/my/exams')->assertForbidden();
        $this->as($student)->getJson('/api/my/exams')->assertOk();
        $this->as($parent)->getJson('/api/my/exams')->assertOk();
    }

    public function test_a_student_login_without_a_student_record_gets_404(): void
    {
        $this->as($this->userWithRole('student'))->getJson('/api/my/results')->assertNotFound();
    }
}
