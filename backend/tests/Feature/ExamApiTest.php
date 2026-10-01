<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamSubject;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExams;
use Tests\TestCase;

class ExamApiTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpExams();
    }

    private function payload(array $extra = []): array
    {
        return [
            'academic_year_id' => $this->year->id,
            'name_en' => 'Half Yearly Exam',
            'code' => 'HY-26',
            'type' => 'half_yearly',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-15',
            'class_ids' => [$this->class9->id, $this->class10->id],
            ...$extra,
        ];
    }

    private function markFor(Exam $exam, Classes $class): ExamMark
    {
        $subject = $exam->examSubjects()->where('class_id', $class->id)->firstOrFail();

        return ExamMark::factory()->create(['exam_subject_id' => $subject->id]);
    }

    // Create

    public function test_store_creates_the_exam_and_snapshots_the_curriculum(): void
    {
        $this->as($this->admin)->postJson('/api/exams', $this->payload())
            ->assertCreated()
            ->assertJsonStructure(['data' => [
                'id', 'academic_year_id', 'academic_year' => ['id', 'year'], 'name_en', 'name_bn', 'code', 'type', 'start_date', 'end_date',
                'status', 'published_at', 'class_ids', 'classes' => [['id', 'number']], 'subjects_count', 'created_at', 'updated_at',
            ], 'message'])
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.code', 'HY-26')
            ->assertJsonPath('data.start_date', '2026-06-01')
            ->assertJsonPath('data.published_at', null)
            ->assertJsonPath('data.class_ids', [$this->class9->id, $this->class10->id])
            ->assertJsonPath('data.subjects_count', 5);

        $exam = Exam::firstOrFail();
        $this->assertSame(4, $exam->examSubjects()->where('class_id', $this->class9->id)->count());
        $this->assertSame(1, $exam->examSubjects()->where('class_id', $this->class10->id)->count());

        $physics = $exam->examSubjects()->where('subject_id', $this->physics->id)->firstOrFail();
        $this->assertSame('science', $physics->group);
        $this->assertSame('compulsory', $physics->type);
        $this->assertSame([50, 17, 25, 8, 25, 8], [$physics->written_full, $physics->written_pass, $physics->mcq_full, $physics->mcq_pass, $physics->practical_full, $physics->practical_pass]);
        $this->assertNull($physics->exam_date);

        $bangla = $exam->examSubjects()->where('subject_id', $this->bangla->id)->where('class_id', $this->class9->id)->firstOrFail();
        $this->assertSame('bangla', $bangla->paper_group);
        $this->assertNull($bangla->practical_full);

        $optional = $exam->examSubjects()->where('subject_id', $this->higherMath->id)->firstOrFail();
        $this->assertSame('optional', $optional->type);
    }

    public function test_later_curriculum_edits_do_not_change_a_past_exam(): void
    {
        $exam = $this->createExam();

        ClassSubject::where('class_id', $this->class9->id)->where('subject_id', $this->physics->id)->update(['written_full' => 99]);
        ClassSubject::where('class_id', $this->class9->id)->where('subject_id', $this->accounting->id)->delete();

        $this->assertSame(50, $exam->examSubjects()->where('subject_id', $this->physics->id)->value('written_full'));
        $this->assertTrue($exam->examSubjects()->where('subject_id', $this->accounting->id)->exists());
    }

    public function test_the_same_subject_in_two_groups_is_kept_as_two_rows(): void
    {
        // Agriculture is the optional subject of every group, General Science compulsory for two.
        $agriculture = Subject::factory()->create(['name' => 'Agriculture']);
        $this->curriculum($this->class9, $agriculture, 'science', 'optional');
        $this->curriculum($this->class9, $agriculture, 'humanities', 'optional');

        $exam = $this->createExam([$this->class9]);

        $this->assertSame(2, $exam->examSubjects()->where('subject_id', $agriculture->id)->count());
    }

    public function test_a_bangla_only_name_is_accepted(): void
    {
        $this->as($this->admin)->postJson('/api/exams', $this->payload(['name_en' => null, 'name_bn' => 'অর্ধবার্ষিক পরীক্ষা']))
            ->assertCreated()
            ->assertJsonPath('data.name_bn', 'অর্ধবার্ষিক পরীক্ষা')
            ->assertJsonPath('data.name_en', null);
    }

    public function test_store_validation_errors(): void
    {
        $this->as($this->admin)->postJson('/api/exams', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['academic_year_id', 'code', 'type', 'start_date', 'end_date', 'class_ids']);

        $this->as($this->admin)->postJson('/api/exams', $this->payload(['name_en' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['name_en']);

        $this->as($this->admin)->postJson('/api/exams', $this->payload(['name_en' => '', 'name_bn' => '']))
            ->assertUnprocessable()->assertJsonValidationErrors(['name_en']);

        $this->as($this->admin)->postJson('/api/exams', $this->payload(['start_date' => '2025-12-31', 'end_date' => '2027-01-01']))
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date', 'end_date']);

        $this->as($this->admin)->postJson('/api/exams', $this->payload(['start_date' => '2026-06-10', 'end_date' => '2026-06-09']))
            ->assertUnprocessable()->assertJsonValidationErrors(['end_date']);

        $this->as($this->admin)->postJson('/api/exams', $this->payload(['type' => 'weekly']))
            ->assertUnprocessable()->assertJsonValidationErrors(['type']);

        $this->as($this->admin)->postJson('/api/exams', $this->payload(['start_date' => '01/06/2026']))
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date']);

        $this->assertSame(0, Exam::count());
    }

    public function test_a_duplicate_code_in_the_same_year_is_rejected_case_insensitively(): void
    {
        $this->createExam();

        $this->as($this->admin)->postJson('/api/exams', $this->payload(['code' => 'hy-2026']))
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);

        // The same code in another year is fine.
        $other = AcademicYear::factory()->create(['year' => 2027, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
        $this->as($this->admin)->postJson('/api/exams', $this->payload([
            'academic_year_id' => $other->id, 'code' => 'HY-2026', 'start_date' => '2027-06-01', 'end_date' => '2027-06-15',
        ]))->assertCreated();
    }

    public function test_a_soft_deleted_exams_code_stays_taken(): void
    {
        $exam = $this->createExam();
        $this->as($this->admin)->deleteJson("/api/exams/{$exam->id}")->assertNoContent();

        $this->as($this->admin)->postJson('/api/exams', $this->payload(['code' => 'HY-2026']))
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
    }

    public function test_unknown_deleted_and_repeated_class_ids_are_rejected(): void
    {
        $deleted = Classes::factory()->create(['number' => 8]);
        $deleted->delete();

        foreach ([[999999], [$deleted->id], [$this->class9->id, $this->class9->id], [], 'nine'] as $classIds) {
            $this->as($this->admin)->postJson('/api/exams', $this->payload(['class_ids' => $classIds]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(array_key_exists(0, (array) $classIds) && $classIds !== [] && ! is_string($classIds) ? ['class_ids.'.(count($classIds) - 1)] : ['class_ids']);
        }

        $this->assertSame(0, Exam::count());
    }

    public function test_a_class_without_a_curriculum_is_rejected_per_class_and_nothing_is_saved(): void
    {
        $empty = Classes::factory()->create(['number' => 8]);

        $this->as($this->admin)->postJson('/api/exams', $this->payload(['class_ids' => [$this->class9->id, $empty->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['class_ids.1']);

        $this->assertSame(0, Exam::count());
        $this->assertSame(0, ExamSubject::count());
    }

    public function test_a_curriculum_subject_without_any_marks_part_is_rejected(): void
    {
        ClassSubject::where('class_id', $this->class10->id)->update(['written_full' => null, 'written_pass' => null, 'mcq_full' => null, 'mcq_pass' => null]);

        $this->as($this->admin)->postJson('/api/exams', $this->payload())
            ->assertUnprocessable()->assertJsonValidationErrors(['class_ids.1']);

        $this->assertSame(0, Exam::count());
    }

    // List and show

    public function test_index_defaults_to_the_active_year_and_filters(): void
    {
        $half = $this->createExam();
        $annual = $this->createExam([$this->class9], ['code' => 'AN-26', 'type' => 'annual', 'name_en' => 'Annual', 'name_bn' => 'বার্ষিক']);
        $other = AcademicYear::factory()->create(['year' => 2025, 'start_date' => '2025-01-01', 'end_date' => '2025-12-31']);
        $old = Exam::factory()->create(['academic_year_id' => $other->id, 'code' => 'OLD']);

        $this->as($this->admin)->getJson('/api/exams')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name_en', 'classes']], 'links', 'meta' => ['total', 'per_page']])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 2);

        $this->as($this->admin)->getJson("/api/exams?academic_year_id={$other->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
        $this->as($this->admin)->getJson('/api/exams?type=annual')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $annual->id);
        $this->as($this->admin)->getJson('/api/exams?status=draft')->assertJsonCount(2, 'data');
        $this->as($this->admin)->getJson('/api/exams?status=marks_entry')->assertJsonCount(0, 'data');
        $this->as($this->admin)->getJson('/api/exams?search='.urlencode('বার্ষিক'))->assertJsonCount(1, 'data');
        $this->as($this->admin)->getJson('/api/exams?search=hy-2026')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $half->id);
        $this->as($this->admin)->getJson('/api/exams?per_page=1')->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 2);
    }

    public function test_index_rejects_bad_filters(): void
    {
        $this->as($this->admin)->getJson('/api/exams?search[]=x')->assertUnprocessable()->assertJsonValidationErrors(['search']);
        $this->as($this->admin)->getJson('/api/exams?type=weekly')->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->as($this->admin)->getJson('/api/exams?status=done')->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->as($this->admin)->getJson('/api/exams?academic_year_id[]=1')->assertUnprocessable()->assertJsonValidationErrors(['academic_year_id']);
    }

    public function test_show_returns_the_exam_and_unknown_ids_are_404(): void
    {
        $exam = $this->createExam();

        $this->as($this->admin)->getJson("/api/exams/{$exam->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $exam->id)
            ->assertJsonPath('data.classes.0.number', 9);

        $this->as($this->admin)->getJson('/api/exams/1abc')->assertNotFound();
        $this->as($this->admin)->getJson('/api/exams/999999')->assertNotFound()->assertJsonPath('message', 'Record not found.');
        $this->as($this->admin)->deleteJson('/api/exams/1abc')->assertNotFound();
    }

    // Update

    public function test_update_changes_the_fields_and_checks_a_partial_date_change(): void
    {
        $exam = $this->createExam();

        $this->as($this->admin)->putJson("/api/exams/{$exam->id}", ['name_bn' => 'অর্ধবার্ষিক', 'type' => 'test', 'code' => 'hy-2026', 'end_date' => '2026-06-20'])
            ->assertOk()
            ->assertJsonPath('data.name_bn', 'অর্ধবার্ষিক')
            ->assertJsonPath('data.type', 'test')
            ->assertJsonPath('data.end_date', '2026-06-20')
            ->assertJsonPath('data.status', 'draft');

        // The saved start date (2026-06-01) is the other end of the range.
        $this->as($this->admin)->putJson("/api/exams/{$exam->id}", ['end_date' => '2026-05-31'])
            ->assertUnprocessable()->assertJsonValidationErrors(['end_date']);
        $this->as($this->admin)->putJson("/api/exams/{$exam->id}", ['start_date' => '2027-01-01'])
            ->assertUnprocessable()->assertJsonValidationErrors(['start_date']);

        // Neither name may be left empty.
        $this->as($this->admin)->putJson("/api/exams/{$exam->id}", ['name_en' => null, 'name_bn' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['name_en']);
        $this->as($this->admin)->putJson("/api/exams/{$exam->id}", ['name_en' => null, 'name_bn' => 'ঠিক আছে'])->assertOk();
    }

    public function test_update_refuses_the_year_and_the_status_and_a_taken_code(): void
    {
        $exam = $this->createExam();
        $other = Exam::factory()->create(['academic_year_id' => $this->year->id, 'code' => 'TAKEN']);

        $this->as($this->admin)->putJson("/api/exams/{$exam->id}", ['academic_year_id' => AcademicYear::factory()->create()->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['academic_year_id']);
        $this->as($this->admin)->putJson("/api/exams/{$exam->id}", ['status' => 'published'])
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->as($this->admin)->putJson("/api/exams/{$exam->id}", ['code' => 'taken'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->as($this->admin)->putJson("/api/exams/{$exam->id}", ['class_ids' => []])
            ->assertUnprocessable()->assertJsonValidationErrors(['class_ids']);
    }

    public function test_update_adds_and_removes_classes(): void
    {
        $exam = $this->createExam([$this->class9]);
        $this->assertSame(4, $exam->examSubjects()->count());

        $this->as($this->admin)->putJson("/api/exams/{$exam->id}", ['class_ids' => [$this->class9->id, $this->class10->id]])
            ->assertOk()->assertJsonPath('data.class_ids', [$this->class9->id, $this->class10->id])->assertJsonPath('data.subjects_count', 5);

        $this->as($this->admin)->putJson("/api/exams/{$exam->id}", ['class_ids' => [$this->class10->id]])
            ->assertOk()->assertJsonPath('data.class_ids', [$this->class10->id])->assertJsonPath('data.subjects_count', 1);

        $this->assertSame(0, $exam->examSubjects()->where('class_id', $this->class9->id)->count());
    }

    public function test_update_refuses_to_remove_a_class_once_marks_exist_for_it(): void
    {
        $exam = $this->createExam();
        $this->markFor($exam, $this->class9);

        $this->as($this->admin)->putJson("/api/exams/{$exam->id}", ['class_ids' => [$this->class10->id]])->assertStatus(409);

        $this->assertSame(5, $exam->examSubjects()->count());

        // The class without marks can still be removed.
        $this->as($this->admin)->putJson("/api/exams/{$exam->id}", ['class_ids' => [$this->class9->id]])->assertOk();
    }

    // Regenerate

    public function test_regenerate_resyncs_one_class_from_the_curriculum_and_keeps_the_schedule(): void
    {
        $exam = $this->createExam();
        $physics = $exam->examSubjects()->where('subject_id', $this->physics->id)->firstOrFail();
        $physics->update(['exam_date' => '2026-06-05', 'start_time' => '10:00', 'end_time' => '13:00']);

        $chemistry = Subject::factory()->create(['name' => 'Chemistry']);
        $this->curriculum($this->class9, $chemistry, 'science', 'compulsory', ['written_full' => 60, 'written_pass' => 20]);
        ClassSubject::where('class_id', $this->class9->id)->where('subject_id', $this->physics->id)->update(['written_full' => 55]);
        ClassSubject::where('class_id', $this->class9->id)->where('subject_id', $this->accounting->id)->delete();
        ClassSubject::where('class_id', $this->class10->id)->update(['written_full' => 1]);

        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/classes/{$this->class9->id}/regenerate")
            ->assertOk()
            ->assertJsonPath('data.id', $exam->id)
            ->assertJsonPath('data.subjects_count', 5);

        $physics->refresh();
        $this->assertSame(55, $physics->written_full);
        $this->assertSame('2026-06-05', $physics->exam_date->toDateString());
        $this->assertTrue($exam->examSubjects()->where('subject_id', $chemistry->id)->exists());
        $this->assertFalse($exam->examSubjects()->where('subject_id', $this->accounting->id)->exists());
        // Another class is untouched.
        $this->assertSame(70, $exam->examSubjects()->where('class_id', $this->class10->id)->value('written_full'));
    }

    public function test_regenerate_is_refused_once_marks_exist_and_for_a_class_not_in_the_exam(): void
    {
        $exam = $this->createExam([$this->class9]);

        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/classes/{$this->class10->id}/regenerate")->assertNotFound();
        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/classes/999999/regenerate")->assertNotFound();
        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/classes/1abc/regenerate")->assertNotFound();

        $this->markFor($exam, $this->class9);

        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/classes/{$this->class9->id}/regenerate")->assertStatus(409);
    }

    public function test_regenerate_is_a_422_when_the_curriculum_is_now_empty(): void
    {
        $exam = $this->createExam([$this->class10]);
        ClassSubject::where('class_id', $this->class10->id)->delete();

        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/classes/{$this->class10->id}/regenerate")
            ->assertUnprocessable()->assertJsonValidationErrors(['class_id']);

        $this->assertSame(1, $exam->examSubjects()->count());
    }

    public function test_regenerate_for_a_class_not_in_the_exam_is_a_404_even_when_its_curriculum_is_empty(): void
    {
        $exam = $this->createExam([$this->class9]);
        ClassSubject::where('class_id', $this->class10->id)->delete();

        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/classes/{$this->class10->id}/regenerate")->assertNotFound();
    }

    // Delete

    public function test_destroy_deletes_an_exam_without_marks_and_frees_its_classes(): void
    {
        $exam = $this->createExam();

        $this->as($this->admin)->deleteJson("/api/exams/{$exam->id}")->assertNoContent();

        $this->assertSoftDeleted($exam);
        $this->assertSame(0, ExamSubject::count());
        $this->as($this->admin)->getJson("/api/exams/{$exam->id}")->assertNotFound();
        $this->as($this->admin)->deleteJson("/api/classes/{$this->class10->id}")->assertNoContent();
    }

    public function test_destroy_is_refused_once_marks_exist(): void
    {
        $exam = $this->createExam();
        $this->markFor($exam, $this->class10);

        $this->as($this->admin)->deleteJson("/api/exams/{$exam->id}")->assertStatus(409);

        $this->assertNotSoftDeleted($exam);
        $this->assertSame(5, $exam->examSubjects()->count());
    }

    // Status

    public function test_open_marks_entry_moves_draft_to_marks_entry_once(): void
    {
        $exam = $this->createExam();

        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/open-marks-entry")
            ->assertOk()->assertJsonPath('data.status', 'marks_entry');

        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/open-marks-entry")->assertStatus(409);

        $exam->update(['status' => 'published']);
        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/open-marks-entry")->assertStatus(409);
        $this->as($this->admin)->postJson('/api/exams/1abc/open-marks-entry')->assertNotFound();
    }

    public function test_publishing_a_draft_exam_is_a_conflict(): void
    {
        $exam = $this->createExam();

        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/publish")->assertStatus(409);
    }

    // Guards

    public function test_exams_block_deleting_their_class_subject_and_year(): void
    {
        $exam = $this->createExam();

        $this->as($this->admin)->deleteJson("/api/classes/{$this->class9->id}")->assertStatus(409);
        $this->as($this->admin)->deleteJson("/api/subjects/{$this->physics->id}")->assertStatus(409);

        // Even a soft-deleted exam still holds the year's restrict foreign key.
        $other = AcademicYear::factory()->create(['year' => 2027, 'start_date' => '2027-01-01', 'end_date' => '2027-12-31']);
        $old = Exam::factory()->create(['academic_year_id' => $other->id]);
        $old->delete();
        $this->as($this->admin)->deleteJson("/api/academic-years/{$other->id}")->assertStatus(409);
    }

    public function test_a_student_with_marks_cannot_be_deleted(): void
    {
        $exam = $this->createExam();
        $mark = $this->markFor($exam, $this->class9);

        $this->as($this->admin)->deleteJson("/api/students/{$mark->student_id}")->assertStatus(409);
    }

    // Authorization

    public function test_authorization(): void
    {
        $this->getJson('/api/exams')->assertUnauthorized();
        $this->postJson('/api/exams', [])->assertUnauthorized();

        $exam = $this->createExam();
        $teacher = $this->userWithRole('teacher');
        $student = $this->userWithRole('student');
        $parent = $this->userWithRole('parent');

        foreach ([$student, $parent] as $user) {
            $this->as($user)->getJson('/api/exams')->assertForbidden();
            $this->as($user)->getJson("/api/exams/{$exam->id}")->assertForbidden();
            $this->as($user)->getJson("/api/exams/{$exam->id}/subjects")->assertForbidden();
            $this->as($user)->getJson("/api/exams/{$exam->id}/marks")->assertForbidden();
        }

        // Teachers can read exams but not change them.
        $this->as($teacher)->getJson('/api/exams')->assertOk();
        $this->as($teacher)->postJson('/api/exams', $this->payload(['code' => 'T']))->assertForbidden();
        $this->as($teacher)->putJson("/api/exams/{$exam->id}", ['type' => 'test'])->assertForbidden();
        $this->as($teacher)->deleteJson("/api/exams/{$exam->id}")->assertForbidden();
        $this->as($teacher)->postJson("/api/exams/{$exam->id}/open-marks-entry")->assertForbidden();
        $this->as($teacher)->postJson("/api/exams/{$exam->id}/classes/{$this->class9->id}/regenerate")->assertForbidden();

        $this->assertSame('draft', $exam->fresh()->status);
    }
}
