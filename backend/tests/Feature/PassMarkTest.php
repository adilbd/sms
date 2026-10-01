<?php

namespace Tests\Feature;

use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamSubject;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\Concerns\BuildsExams;
use Tests\TestCase;

/** The Bangladesh pass mark is 33%, not the legacy 40. */
class PassMarkTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    public function test_a_subject_defaults_to_a_pass_mark_of_33(): void
    {
        $id = DB::table('subjects')->insertGetId(['name' => 'Raw', 'code' => 'RAW', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(33, (int) DB::table('subjects')->where('id', $id)->value('pass_marks'));
        $this->assertSame(33, (new Subject)->pass_marks);
    }

    public function test_a_37_out_of_100_written_only_paper_is_a_d_and_the_student_passes(): void
    {
        $this->setUpExams();
        $math = Subject::factory()->create(['name' => 'Math']);
        $this->curriculum($this->class10, $math, null, 'compulsory', ['written_full' => 100, 'written_pass' => 33]);
        $section = \App\Models\Section::factory()->create(['class_id' => $this->class10->id, 'shift_id' => $this->shift->id]);
        $ten = $this->enrol($section, null, null, 1);

        $exam = $this->createExam([$this->class10]);
        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/open-marks-entry")->assertOk();

        foreach ([[$this->bangla, 70, 30], [$math, 37, null]] as [$subject, $written, $mcq]) {
            ExamMark::create([
                'exam_subject_id' => ExamSubject::where('exam_id', $exam->id)->where('subject_id', $subject->id)->value('id'),
                'student_id' => $ten->student_id, 'enrolment_id' => $ten->id,
                'written' => $written, 'mcq' => $mcq, 'practical' => null, 'is_absent' => false,
            ]);
        }

        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/process")->assertOk();

        $this->as($this->admin)->getJson("/api/exams/{$exam->id}/results/{$ten->student_id}")
            ->assertOk()
            ->assertJsonPath('data.is_pass', true)
            ->assertJsonPath('data.failed_count', 0)
            ->assertJsonPath('data.gpa', '3.00')
            ->assertJsonPath('data.section.shift.name_en', $this->shift->name_en);

        $math37 = collect($this->as($this->admin)->getJson("/api/exams/{$exam->id}/results/{$ten->student_id}")->json('data.subjects'))
            ->firstWhere('name_en', 'Math');
        $this->assertSame('D', $math37['grade']);
        $this->assertSame('1.00', $math37['point']);

        // The report cards read the shift from the tabulation as well.
        $this->as($this->admin)->getJson("/api/exams/{$exam->id}/results?section_id={$section->id}&with_subjects=1")
            ->assertOk()
            ->assertJsonPath('data.0.section.shift.name_en', $this->shift->name_en)
            ->assertJsonPath('data.0.section.shift.name_bn', $this->shift->name_bn);
    }

    public function test_the_migration_fixes_legacy_40s_only(): void
    {
        $this->setUpExams();
        $legacy = Subject::factory()->create(['total_marks' => 100, 'pass_marks' => 40]);
        $custom = Subject::factory()->create(['total_marks' => 50, 'pass_marks' => 17]);
        $keepsSeventy = Subject::factory()->create(['total_marks' => 100, 'pass_marks' => 45]);

        $legacyRow = $this->curriculum($this->class9, $legacy, null, 'compulsory', ['written_full' => 100, 'written_pass' => 40]);
        $customRow = $this->curriculum($this->class9, $custom, null, 'compulsory', ['written_full' => 50, 'written_pass' => 17]);
        $partsRow = $this->curriculum($this->class10, $legacy, null, 'compulsory', [
            'written_full' => 100, 'written_pass' => 40, 'mcq_full' => 10, 'mcq_pass' => 4,
        ]);

        $draft = $this->examWithRow($legacy, 'DRAFT-1', Exam::STATUS_DRAFT);
        $entry = $this->examWithRow($legacy, 'ENTRY-1', Exam::STATUS_MARKS_ENTRY);
        $processed = $this->examWithRow($legacy, 'PROC-1', Exam::STATUS_PROCESSED);
        $published = $this->examWithRow($legacy, 'PUB-1', Exam::STATUS_PUBLISHED);
        $customExam = $this->examWithRow($custom, 'CUSTOM-1', Exam::STATUS_DRAFT, 50, 17);

        $migration = require database_path('migrations/2026_10_06_000001_use_the_33_percent_pass_mark_default.php');
        (new ReflectionMethod($migration, 'fixLegacyPassMarks'))->invoke($migration);

        $this->assertSame(33, $legacy->fresh()->pass_marks);
        $this->assertSame(17, $custom->fresh()->pass_marks);
        $this->assertSame(45, $keepsSeventy->fresh()->pass_marks);

        $this->assertSame(33, ClassSubject::find($legacyRow->id)->written_pass);
        $this->assertSame(17, ClassSubject::find($customRow->id)->written_pass);
        $this->assertSame(40, ClassSubject::find($partsRow->id)->written_pass);

        $this->assertSame(33, $draft->written_pass);
        $this->assertSame(33, $entry->written_pass);
        $this->assertSame(40, $processed->written_pass);
        $this->assertSame(40, $published->written_pass);
        $this->assertSame(17, $customExam->written_pass);
    }

    /** An exam_subjects row in an exam of the given status, returning a getter-backed model. */
    private function examWithRow(Subject $subject, string $code, string $status, int $full = 100, int $pass = 40): object
    {
        $exam = Exam::create([
            'academic_year_id' => $this->year->id, 'name_en' => $code, 'code' => $code, 'type' => 'half_yearly',
            'start_date' => '2026-06-01', 'end_date' => '2026-06-15', 'status' => $status,
        ]);
        $row = ExamSubject::create([
            'exam_id' => $exam->id, 'class_id' => $this->class9->id, 'subject_id' => $subject->id, 'type' => 'compulsory',
            'written_full' => $full, 'written_pass' => $pass,
        ]);

        return new class($row)
        {
            public function __construct(private ExamSubject $row) {}

            public function __get(string $name): mixed
            {
                return $this->row->fresh()->{$name};
            }
        };
    }
}
