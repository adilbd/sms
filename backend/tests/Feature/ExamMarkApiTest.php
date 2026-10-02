<?php

namespace Tests\Feature;

use App\Models\Classes;
use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamSubject;
use App\Models\Section;
use App\Models\Staff;
use App\Models\StudentEnrolment;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExams;
use Tests\TestCase;

class ExamMarkApiTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private Exam $exam;

    private StudentEnrolment $scienceHm;

    private StudentEnrolment $science;

    private StudentEnrolment $business;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpExams();

        $this->scienceHm = $this->enrol($this->section9, 'science', $this->higherMath, 1, ['name_en' => 'Sci Hm']);
        $this->science = $this->enrol($this->section9, 'science', null, 2, ['name_en' => 'Sci Plain']);
        $this->business = $this->enrol($this->section9, 'business_studies', null, 3, ['name_en' => 'Business']);

        $this->exam = $this->createExam();
        $this->as($this->admin)->postJson("/api/exams/{$this->exam->id}/open-marks-entry")->assertOk();
    }

    private function row(Subject $subject, ?Classes $class = null): ExamSubject
    {
        return $this->exam->examSubjects()->where('subject_id', $subject->id)->where('class_id', ($class ?? $this->class9)->id)->firstOrFail();
    }

    private function sheetUrl(Subject $subject, ?Section $section = null): string
    {
        return "/api/exams/{$this->exam->id}/marks?section_id=".($section ?? $this->section9)->id.'&exam_subject_id='.$this->row($subject)->id;
    }

    private function save(Subject $subject, array $marks, $as = null, ?Section $section = null)
    {
        return $this->as($as ?? $this->admin)->putJson("/api/exams/{$this->exam->id}/marks", [
            'section_id' => ($section ?? $this->section9)->id,
            'exam_subject_id' => $this->row($subject)->id,
            'marks' => $marks,
        ]);
    }

    /** @return list<int> */
    private function studentIds($response): array
    {
        return array_column($response->json('data.students'), 'student_id');
    }

    // Sheet: who is on it

    public function test_the_sheet_lists_only_the_students_who_take_the_subject(): void
    {
        $physics = $this->as($this->admin)->getJson($this->sheetUrl($this->physics))->assertOk();
        $this->assertSame([$this->scienceHm->student_id, $this->science->student_id], $this->studentIds($physics));

        $accounting = $this->as($this->admin)->getJson($this->sheetUrl($this->accounting))->assertOk();
        $this->assertSame([$this->business->student_id], $this->studentIds($accounting));

        // Only the students who chose Higher Math as their 4th subject.
        $math = $this->as($this->admin)->getJson($this->sheetUrl($this->higherMath))->assertOk();
        $this->assertSame([$this->scienceHm->student_id], $this->studentIds($math));

        // A common subject: everyone.
        $bangla = $this->as($this->admin)->getJson($this->sheetUrl($this->bangla))->assertOk();
        $this->assertSame([$this->scienceHm->student_id, $this->science->student_id, $this->business->student_id], $this->studentIds($bangla));
    }

    public function test_the_sheet_response_shape(): void
    {
        $this->as($this->admin)->getJson($this->sheetUrl($this->physics))
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'exam_subject' => ['id', 'written_full', 'mcq_full', 'practical_full', 'subject' => ['id', 'name']],
                'section' => ['id', 'class_id', 'name', 'code', 'group'],
                'students' => [['student_id', 'enrolment_id', 'student_code', 'name_en', 'name_bn', 'roll_number', 'group', 'written', 'mcq', 'practical', 'is_absent']],
            ]])
            ->assertJsonPath('data.students.0.name_en', 'Sci Hm')
            ->assertJsonPath('data.students.0.written', null)
            ->assertJsonPath('data.students.0.is_absent', false)
            ->assertJsonMissingPath('data.students.0.guardian_mobile');
    }

    public function test_students_below_class_nine_get_only_the_common_subjects(): void
    {
        $class5 = Classes::factory()->create(['number' => 5]);
        $section5 = Section::factory()->create(['class_id' => $class5->id, 'shift_id' => $this->shift->id]);
        $math = Subject::factory()->create(['name' => 'Math']);
        $this->curriculum($class5, $math, null, 'compulsory');
        $enrolment = $this->enrol($section5, null, null, 1);
        $exam = $this->createExam([$class5], ['code' => 'C5']);
        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/open-marks-entry")->assertOk();
        $row = $exam->examSubjects()->firstOrFail();

        $response = $this->as($this->admin)->getJson("/api/exams/{$exam->id}/marks?section_id={$section5->id}&exam_subject_id={$row->id}")->assertOk();

        $this->assertSame([$enrolment->student_id], $this->studentIds($response));
        $this->assertSame(1, $exam->examSubjects()->count());
    }

    public function test_students_who_left_or_were_deleted_are_off_the_sheet(): void
    {
        $this->science->update(['status' => StudentEnrolment::STATUS_LEFT]);
        $this->business->student->delete();
        $otherYear = StudentEnrolment::factory()->create([
            'academic_year_id' => \App\Models\AcademicYear::factory()->create()->id, 'section_id' => $this->section9->id,
        ]);

        $response = $this->as($this->admin)->getJson($this->sheetUrl($this->bangla))->assertOk();

        $this->assertSame([$this->scienceHm->student_id], $this->studentIds($response));
        $this->assertNotContains($otherYear->student_id, $this->studentIds($response));
    }

    public function test_the_sheet_validates_its_query_and_rejects_mismatches(): void
    {
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/marks")
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id', 'exam_subject_id']);

        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/marks?section_id=999999&exam_subject_id=999999")
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id', 'exam_subject_id']);

        // A section of another class.
        $section10 = Section::factory()->create(['class_id' => $this->class10->id, 'shift_id' => $this->shift->id]);
        $this->as($this->admin)->getJson($this->sheetUrl($this->physics, $section10))
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id']);

        // A subject of another exam.
        $other = $this->createExam([$this->class9], ['code' => 'OTHER']);
        $foreign = $other->examSubjects()->firstOrFail();
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/marks?section_id={$this->section9->id}&exam_subject_id={$foreign->id}")
            ->assertUnprocessable()->assertJsonValidationErrors(['exam_subject_id']);

        $this->as($this->admin)->getJson('/api/exams/1abc/marks')->assertNotFound();
        $this->as($this->admin)->putJson('/api/exams/1abc/marks', [])->assertNotFound();
        $this->as($this->admin)->getJson('/api/exams/999999/marks?section_id=1&exam_subject_id=1')->assertNotFound();
    }

    // Save

    public function test_an_admin_saves_the_sheet_and_reloading_shows_the_marks(): void
    {
        $this->save($this->physics, [
            ['student_id' => $this->scienceHm->student_id, 'written' => 40, 'mcq' => 20.5, 'practical' => 25, 'is_absent' => false],
            ['student_id' => $this->science->student_id, 'is_absent' => true],
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Marks saved successfully')
            ->assertJsonPath('data.students.0.written', '40.00')
            ->assertJsonPath('data.students.0.mcq', '20.50')
            ->assertJsonPath('data.students.0.practical', '25.00')
            ->assertJsonPath('data.students.1.is_absent', true)
            ->assertJsonPath('data.students.1.written', null);

        $this->as($this->admin)->getJson($this->sheetUrl($this->physics))
            ->assertOk()
            ->assertJsonPath('data.students.0.written', '40.00')
            ->assertJsonPath('data.students.1.is_absent', true);

        $mark = ExamMark::where('student_id', $this->scienceHm->student_id)->firstOrFail();
        $this->assertSame($this->admin->id, $mark->entered_by);
        $this->assertSame($this->scienceHm->id, $mark->enrolment_id);
        $this->assertSame($this->row($this->physics)->id, $mark->exam_subject_id);
    }

    public function test_saving_again_updates_in_place_and_a_blank_row_clears_the_mark(): void
    {
        $student = $this->scienceHm->student_id;
        $this->save($this->physics, [['student_id' => $student, 'written' => 40], ['student_id' => $this->science->student_id, 'written' => 30]])->assertOk();
        $this->save($this->physics, [['student_id' => $student, 'written' => 45, 'mcq' => 10]])->assertOk();

        $this->assertSame(2, ExamMark::count());
        $this->assertSame('45.00', ExamMark::where('student_id', $student)->value('written'));
        // The student left out of the second save keeps their marks.
        $this->assertSame('30.00', ExamMark::where('student_id', $this->science->student_id)->value('written'));

        $this->save($this->physics, [['student_id' => $student]])->assertOk()->assertJsonPath('data.students.0.written', null);
        $this->assertSame(1, ExamMark::count());
    }

    public function test_zero_marks_are_saved_and_a_blank_sheet_creates_nothing(): void
    {
        $this->save($this->physics, [['student_id' => $this->scienceHm->student_id, 'written' => 0]])->assertOk();
        $this->assertSame('0.00', ExamMark::firstOrFail()->written);

        ExamMark::query()->delete();
        $this->save($this->physics, [['student_id' => $this->scienceHm->student_id, 'written' => null, 'mcq' => '', 'is_absent' => false]])->assertOk();
        $this->assertSame(0, ExamMark::count());
    }

    public function test_a_part_above_its_full_mark_is_a_422_keyed_per_row_and_nothing_is_saved(): void
    {
        $this->save($this->physics, [
            ['student_id' => $this->scienceHm->student_id, 'written' => 40],
            ['student_id' => $this->science->student_id, 'written' => 50.01, 'mcq' => 26, 'practical' => 25],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['marks.1.written', 'marks.1.mcq'])
            ->assertJsonMissingValidationErrors(['marks.0.written', 'marks.1.practical']);

        $this->assertSame(0, ExamMark::count());
    }

    public function test_a_part_the_subject_does_not_have_must_be_empty(): void
    {
        // Accounting has written and MCQ only.
        $this->save($this->accounting, [['student_id' => $this->business->student_id, 'written' => 10, 'practical' => 5]])
            ->assertUnprocessable()->assertJsonValidationErrors(['marks.0.practical']);

        $this->save($this->accounting, [['student_id' => $this->business->student_id, 'written' => 10, 'practical' => null]])->assertOk();
    }

    public function test_an_absent_student_must_have_every_part_empty(): void
    {
        $this->save($this->physics, [['student_id' => $this->scienceHm->student_id, 'written' => 0, 'is_absent' => true]])
            ->assertUnprocessable()->assertJsonValidationErrors(['marks.0.is_absent']);

        $this->assertSame(0, ExamMark::count());
    }

    public function test_a_student_who_is_not_on_the_sheet_is_a_422_on_that_row(): void
    {
        // The business student doesn't take Physics; an unknown id isn't on any sheet.
        $this->save($this->physics, [
            ['student_id' => $this->scienceHm->student_id, 'written' => 40],
            ['student_id' => $this->business->student_id, 'written' => 40],
            ['student_id' => 999999, 'written' => 40],
        ])->assertUnprocessable()->assertJsonValidationErrors(['marks.1.student_id', 'marks.2.student_id']);

        $this->assertSame(0, ExamMark::count());
    }

    public function test_a_section_from_another_class_is_a_422(): void
    {
        $section10 = Section::factory()->create(['class_id' => $this->class10->id, 'shift_id' => $this->shift->id]);

        $this->save($this->physics, [['student_id' => $this->scienceHm->student_id, 'written' => 40]], section: $section10)
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id']);
    }

    public function test_the_payload_is_validated(): void
    {
        $student = $this->scienceHm->student_id;

        $this->as($this->admin)->putJson("/api/exams/{$this->exam->id}/marks", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id', 'exam_subject_id', 'marks']);

        $this->save($this->physics, [])->assertUnprocessable()->assertJsonValidationErrors(['marks']);
        $this->save($this->physics, [['written' => 5]])->assertUnprocessable()->assertJsonValidationErrors(['marks.0.student_id']);
        $this->save($this->physics, [['student_id' => $student, 'written' => 5], ['student_id' => $student, 'written' => 6]])
            ->assertUnprocessable()->assertJsonValidationErrors(['marks.0.student_id', 'marks.1.student_id']);
        $this->save($this->physics, [['student_id' => $student, 'written' => 'abc']])->assertUnprocessable()->assertJsonValidationErrors(['marks.0.written']);
        $this->save($this->physics, [['student_id' => $student, 'written' => -1]])->assertUnprocessable()->assertJsonValidationErrors(['marks.0.written']);
        $this->save($this->physics, [['student_id' => $student, 'written' => 1.234]])->assertUnprocessable()->assertJsonValidationErrors(['marks.0.written']);
        $this->save($this->physics, [['student_id' => $student, 'is_absent' => 'maybe']])->assertUnprocessable()->assertJsonValidationErrors(['marks.0.is_absent']);
    }

    // Status

    public function test_saving_while_the_exam_is_a_draft_or_published_is_a_409(): void
    {
        $draft = $this->createExam([$this->class9], ['code' => 'DRAFT']);
        $row = $draft->examSubjects()->where('subject_id', $this->bangla->id)->firstOrFail();
        $url = "/api/exams/{$draft->id}/marks";
        $payload = [
            'section_id' => $this->section9->id, 'exam_subject_id' => $row->id,
            'marks' => [['student_id' => $this->scienceHm->student_id, 'written' => 10]],
        ];

        $this->as($this->admin)->putJson($url, $payload)->assertStatus(409);

        $draft->update(['status' => Exam::STATUS_PUBLISHED]);
        $this->as($this->admin)->putJson($url, $payload)->assertStatus(409);

        $this->assertSame(0, ExamMark::count());
    }

    // Who may enter marks

    public function test_the_assigned_teacher_can_read_and_save(): void
    {
        $teacher = $this->assignedTeacher($this->section9, $this->physics);

        $this->as($teacher)->getJson($this->sheetUrl($this->physics))->assertOk();
        $this->save($this->physics, [['student_id' => $this->scienceHm->student_id, 'written' => 40]], $teacher)->assertOk();

        $this->assertSame($teacher->id, ExamMark::firstOrFail()->entered_by);
    }

    public function test_two_teachers_on_one_subject_can_both_save_marks(): void
    {
        $first = $this->assignedTeacher($this->section9, $this->physics);
        $second = $this->assignedTeacher($this->section9, $this->physics);
        $unassigned = $this->assignedTeacher($this->section9, $this->accounting);

        $this->save($this->physics, [['student_id' => $this->scienceHm->student_id, 'written' => 40]], $first)->assertOk();
        $this->save($this->physics, [['student_id' => $this->scienceHm->student_id, 'written' => 42]], $second)->assertOk();
        $this->as($second)->getJson($this->sheetUrl($this->physics))->assertOk();

        $this->assertSame($second->id, ExamMark::firstOrFail()->entered_by);
        $this->save($this->physics, [['student_id' => $this->scienceHm->student_id, 'written' => 10]], $unassigned)->assertForbidden();
    }

    public function test_an_unassigned_teacher_is_refused_on_both_actions(): void
    {
        // Assigned to Physics, so not to Bangla; and a teacher assigned to nothing.
        $physicsTeacher = $this->assignedTeacher($this->section9, $this->physics);
        $idle = $this->assignedTeacher($this->section9, $this->accounting);

        foreach ([$physicsTeacher, $idle] as $teacher) {
            $this->as($teacher)->getJson($this->sheetUrl($this->bangla))->assertForbidden();
            $this->save($this->bangla, [['student_id' => $this->scienceHm->student_id, 'written' => 40]], $teacher)->assertForbidden();
        }

        $this->assertSame(0, ExamMark::count());
    }

    public function test_a_teacher_assigned_to_another_section_is_refused(): void
    {
        $sectionB = Section::factory()->create(['class_id' => $this->class9->id, 'shift_id' => $this->shift->id]);
        $teacher = $this->assignedTeacher($sectionB, $this->physics);

        $this->save($this->physics, [['student_id' => $this->scienceHm->student_id, 'written' => 40]], $teacher)->assertForbidden();
        $this->as($teacher)->getJson($this->sheetUrl($this->physics, $sectionB))->assertOk();
    }

    public function test_a_retired_or_transferred_teacher_is_refused(): void
    {
        foreach ([Staff::STATUS_RETIRED, Staff::STATUS_TRANSFERRED] as $status) {
            $teacher = $this->assignedTeacher($this->section9, $this->bangla, ['status' => $status, 'leaving_date' => '2026-06-30']);
            $this->save($this->bangla, [['student_id' => $this->scienceHm->student_id, 'written' => 40]], $teacher)->assertForbidden();
            // Free the assignment for the next teacher.
            \App\Models\SubjectAssignment::query()->delete();
        }

        $this->assertSame(0, ExamMark::count());
    }

    public function test_a_teacher_with_no_staff_link_is_refused(): void
    {
        $teacher = $this->userWithRole('teacher');

        $this->as($teacher)->getJson($this->sheetUrl($this->physics))->assertForbidden();
        $this->save($this->physics, [['student_id' => $this->scienceHm->student_id, 'written' => 40]], $teacher)
            ->assertForbidden()
            ->assertJsonPath('message', 'Only the assigned subject teacher or an admin can enter marks.');
    }

    public function test_the_student_and_parent_roles_have_no_access(): void
    {
        foreach (['student', 'parent'] as $role) {
            $user = $this->userWithRole($role);

            $this->as($user)->getJson($this->sheetUrl($this->physics))->assertForbidden();
            $this->save($this->physics, [['student_id' => $this->scienceHm->student_id, 'written' => 40]], $user)->assertForbidden();
        }
    }

    public function test_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson("/api/exams/{$this->exam->id}/marks")->assertUnauthorized();
        $this->putJson("/api/exams/{$this->exam->id}/marks", [])->assertUnauthorized();
    }
}
