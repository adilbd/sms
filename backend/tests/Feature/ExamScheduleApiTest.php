<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExams;
use Tests\TestCase;

class ExamScheduleApiTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private Exam $exam;

    private ExamSubject $physicsRow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpExams();
        $this->exam = $this->createExam();
        $this->physicsRow = $this->exam->examSubjects()->where('subject_id', $this->physics->id)->firstOrFail();
    }

    private function edit(array $data, ?ExamSubject $subject = null, ?Exam $exam = null)
    {
        $exam ??= $this->exam;
        $subject ??= $this->physicsRow;

        return $this->as($this->admin)->putJson("/api/exams/{$exam->id}/subjects/{$subject->id}", $data);
    }

    public function test_the_exam_date_must_be_within_the_exams_dates(): void
    {
        // createExam() holds the exam from 2026-06-01 to 2026-06-15.
        $this->edit(['exam_date' => '2026-05-31'])->assertUnprocessable()->assertJsonValidationErrors(['exam_date']);
        $this->edit(['exam_date' => '2026-06-16'])->assertUnprocessable()->assertJsonValidationErrors(['exam_date']);
        $this->edit(['exam_date' => '2026-06-01'])->assertOk();
        $this->edit(['exam_date' => '2026-06-15'])->assertOk()->assertJsonPath('data.exam_date', '2026-06-15');
    }

    public function test_index_lists_the_schedule_in_class_then_curriculum_order(): void
    {
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/subjects")
            ->assertOk()
            ->assertJsonStructure(['data' => [[
                'id', 'exam_id', 'class_id', 'class' => ['id', 'number'], 'subject_id', 'subject' => ['id', 'name'], 'group', 'type', 'paper_group',
                'written_full', 'written_pass', 'mcq_full', 'mcq_pass', 'practical_full', 'practical_pass', 'exam_date', 'start_time', 'end_time', 'sort_order',
            ]]])
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.class_id', $this->class9->id)
            ->assertJsonPath('data.0.subject.name', 'Bangla')
            ->assertJsonPath('data.0.paper_group', 'bangla')
            ->assertJsonPath('data.4.class_id', $this->class10->id);
    }

    public function test_index_filters_by_class(): void
    {
        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/subjects?class_id={$this->class10->id}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.class_id', $this->class10->id);

        $this->as($this->admin)->getJson("/api/exams/{$this->exam->id}/subjects?class_id[]=1")
            ->assertUnprocessable()->assertJsonValidationErrors(['class_id']);
    }

    public function test_update_edits_the_date_times_and_part_marks(): void
    {
        $this->edit([
            'exam_date' => '2026-06-04', 'start_time' => '10:00', 'end_time' => '13:00',
            'written_full' => 60, 'written_pass' => 20, 'practical_full' => null, 'practical_pass' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.exam_date', '2026-06-04')
            ->assertJsonPath('data.start_time', '10:00')
            ->assertJsonPath('data.end_time', '13:00')
            ->assertJsonPath('data.written_full', 60)
            ->assertJsonPath('data.practical_full', null)
            ->assertJsonPath('data.subject.name', 'Physics');

        $this->physicsRow->refresh();
        $this->assertSame(60, $this->physicsRow->written_full);
        $this->assertNull($this->physicsRow->practical_pass);

        $this->edit(['exam_date' => null, 'start_time' => null, 'end_time' => null])
            ->assertOk()->assertJsonPath('data.exam_date', null)->assertJsonPath('data.start_time', null);
    }

    public function test_update_checks_the_part_rules_against_the_saved_subject(): void
    {
        // Only the pass mark is sent: it exceeds the saved full mark (25).
        $this->edit(['mcq_pass' => 26])->assertUnprocessable()->assertJsonValidationErrors(['mcq_pass']);
        $this->edit(['written_full' => 10])->assertUnprocessable()->assertJsonValidationErrors(['written_pass']);
        $this->edit(['written_full' => 0, 'written_pass' => 0])->assertUnprocessable()->assertJsonValidationErrors(['written_full']);
        $this->edit(['written_full' => null, 'written_pass' => null, 'mcq_full' => null, 'mcq_pass' => null, 'practical_full' => null, 'practical_pass' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['written_full']);
        $this->edit(['mcq_pass' => null])->assertUnprocessable()->assertJsonValidationErrors(['mcq_pass']);

        $this->assertSame(50, $this->physicsRow->fresh()->written_full);
    }

    public function test_update_validates_the_payload(): void
    {
        $this->edit(['exam_date' => '04/06/2026'])->assertUnprocessable()->assertJsonValidationErrors(['exam_date']);
        $this->edit(['start_time' => '25:00'])->assertUnprocessable()->assertJsonValidationErrors(['start_time']);
        $this->edit(['end_time' => '10:00:00'])->assertUnprocessable()->assertJsonValidationErrors(['end_time']);
        $this->edit(['start_time' => '12:00', 'end_time' => '11:00'])->assertUnprocessable()->assertJsonValidationErrors(['end_time']);
        $this->edit(['written_full' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors(['written_full']);
        $this->edit(['written_full' => 1001])->assertUnprocessable()->assertJsonValidationErrors(['written_full']);
        $this->edit(['written_full' => 1.5])->assertUnprocessable()->assertJsonValidationErrors(['written_full']);
    }

    public function test_changing_the_part_marks_is_a_409_once_marks_exist_but_the_date_is_not(): void
    {
        ExamMark::factory()->create(['exam_subject_id' => $this->physicsRow->id]);

        $this->edit(['written_full' => 60])->assertStatus(409);
        $this->edit(['mcq_pass' => 7])->assertStatus(409);
        $this->assertSame(50, $this->physicsRow->fresh()->written_full);

        $this->edit(['exam_date' => '2026-06-04', 'start_time' => '09:00'])->assertOk();
        // Sending the saved marks back along with the date is not a change.
        $this->edit(['written_full' => 50, 'written_pass' => 17, 'exam_date' => '2026-06-05'])->assertOk();
    }

    public function test_an_exam_subject_of_another_exam_or_an_unknown_one_is_404(): void
    {
        $other = $this->createExam([$this->class9], ['code' => 'OTHER']);

        $this->edit(['exam_date' => '2026-06-04'], $this->physicsRow, $other)->assertNotFound();
        $this->as($this->admin)->putJson("/api/exams/{$this->exam->id}/subjects/999999", ['exam_date' => '2026-06-04'])->assertNotFound();
        $this->as($this->admin)->putJson("/api/exams/{$this->exam->id}/subjects/1abc", [])->assertNotFound();
        $this->as($this->admin)->getJson('/api/exams/1abc/subjects')->assertNotFound();
        $this->as($this->admin)->getJson('/api/exams/999999/subjects')->assertNotFound();
    }

    public function test_authorization(): void
    {
        $teacher = $this->userWithRole('teacher');
        $student = $this->userWithRole('student');

        $this->as($teacher)->getJson("/api/exams/{$this->exam->id}/subjects")->assertOk();
        $this->as($teacher)->putJson("/api/exams/{$this->exam->id}/subjects/{$this->physicsRow->id}", ['exam_date' => '2026-06-04'])->assertForbidden();
        $this->as($student)->getJson("/api/exams/{$this->exam->id}/subjects")->assertForbidden();
        $this->as($student)->putJson("/api/exams/{$this->exam->id}/subjects/{$this->physicsRow->id}", [])->assertForbidden();
    }
}
