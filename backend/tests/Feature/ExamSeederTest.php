<?php

namespace Tests\Feature;

use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\ExamSubject;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use Database\Seeders\AcademicYearSeeder;
use Database\Seeders\ClassSeeder;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\ExamSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SectionSeeder;
use Database\Seeders\ShiftSeeder;
use Database\Seeders\StudentSeeder;
use Database\Seeders\SubjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedPrerequisites(): void
    {
        $this->seed([
            RolePermissionSeeder::class, ShiftSeeder::class, ClassSeeder::class, SubjectSeeder::class,
            CurriculumSeeder::class, AcademicYearSeeder::class, SectionSeeder::class, StudentSeeder::class,
        ]);
    }

    public function test_seeds_one_half_yearly_exam_in_mark_entry_with_marks_for_section_a(): void
    {
        $this->seedPrerequisites();

        $this->seed(ExamSeeder::class);

        $exam = Exam::firstOrFail();
        $this->assertSame(1, Exam::count());
        $this->assertSame('HY-2026', $exam->code);
        $this->assertSame('half_yearly', $exam->type);
        $this->assertSame(Exam::STATUS_MARKS_ENTRY, $exam->status);
        $this->assertSame([9, 10], $exam->classes->pluck('number')->all());
        $this->assertSame(2026, $exam->academicYear->year);

        // A schedule with dates, never on a Friday.
        $this->assertSame(0, ExamSubject::whereNull('exam_date')->count());
        foreach (ExamSubject::all() as $subject) {
            $this->assertFalse($subject->exam_date->isFriday());
        }

        // Every sheet student of Section A (Morning) has a mark for every subject they take.
        $this->assertGreaterThan(0, ExamMark::count());
        $this->assertSame(10, Student::whereHas('enrolments', fn ($q) => $q->whereIn('class_id', $exam->classes->pluck('id')))->count());
        $this->assertSame(4, ExamMark::where('is_absent', true)->count(), 'two absences per class');
    }

    public function test_marks_are_valid_varied_and_follow_the_subjects_parts(): void
    {
        $this->seedPrerequisites();
        $this->seed(ExamSeeder::class);

        $absent = 0;
        $belowPass = 0;
        $values = [];

        foreach (ExamMark::with('examSubject')->get() as $mark) {
            if ($mark->is_absent) {
                $absent++;
                $this->assertNull($mark->written);
                $this->assertNull($mark->mcq);
                $this->assertNull($mark->practical);

                continue;
            }

            foreach (['written', 'mcq', 'practical'] as $part) {
                $full = $mark->examSubject->{"{$part}_full"};

                if ($full === null) {
                    $this->assertNull($mark->{$part}, "{$part} on a subject without it");

                    continue;
                }

                $this->assertNotNull($mark->{$part});
                $this->assertGreaterThanOrEqual(0, (float) $mark->{$part});
                $this->assertLessThanOrEqual($full, (float) $mark->{$part});
                $values[] = $mark->{$part};

                if ((float) $mark->{$part} < $mark->examSubject->{"{$part}_pass"}) {
                    $belowPass++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(2, $absent);
        $this->assertGreaterThan(0, $belowPass, 'some parts are below their pass mark');
        $this->assertGreaterThan(10, count(array_unique($values)), 'marks are varied');
    }

    public function test_the_marks_are_deterministic(): void
    {
        $this->seedPrerequisites();
        $this->seed(ExamSeeder::class);
        $first = ExamMark::orderBy('id')->get(['student_id', 'written', 'mcq', 'practical', 'is_absent'])->toArray();

        ExamMark::query()->delete();
        ExamSubject::query()->delete();
        Exam::withTrashed()->forceDelete();
        $this->seed(ExamSeeder::class);

        $this->assertSame($first, ExamMark::orderBy('id')->get(['student_id', 'written', 'mcq', 'practical', 'is_absent'])->toArray());
    }

    public function test_re_running_adds_nothing_and_keeps_edited_marks(): void
    {
        $this->seedPrerequisites();
        $this->seed(ExamSeeder::class);
        $counts = [Exam::count(), ExamSubject::count(), ExamMark::count()];

        $mark = ExamMark::firstOrFail();
        $mark->update(['written' => 1]);

        $this->seed(ExamSeeder::class);

        $this->assertSame($counts, [Exam::count(), ExamSubject::count(), ExamMark::count()]);
        $this->assertSame('1.00', $mark->fresh()->written);
    }

    public function test_the_seeded_exam_can_be_processed_published_and_read_back(): void
    {
        $this->seedPrerequisites();
        $this->seed(ExamSeeder::class);
        $exam = Exam::firstOrFail();
        $admin = User::where('email', 'admin@sms.com')->firstOrFail();

        $summary = $this->actingAs($admin, 'sanctum')->postJson("/api/exams/{$exam->id}/process")
            ->assertOk()
            ->assertJsonPath('data.exam.status', 'processed')
            ->json('data.summary');

        // Every active enrolment of Classes 9 and 10 gets a result.
        $enrolments = StudentEnrolment::where('status', 'active')->whereIn('class_id', $exam->classes->pluck('id'))->count();
        $this->assertGreaterThan(0, $enrolments);
        $this->assertSame($enrolments, ExamResult::where('exam_id', $exam->id)->count());
        $this->assertSame($enrolments, array_sum(array_column($summary, 'students')));
        $this->assertSame($enrolments, array_sum(array_column($summary, 'passed')) + array_sum(array_column($summary, 'failed')));

        // The seeded students are all in the Section A that has marks, and an absence is a
        // recorded mark, not a missing one.
        $this->assertSame(0, array_sum(array_column($summary, 'missing_marks')));

        // Every GPA is on the scale, and a failed result is GPA 0.00 with an F.
        foreach (ExamResult::where('exam_id', $exam->id)->get() as $result) {
            $this->assertGreaterThanOrEqual(0, (float) $result->gpa);
            $this->assertLessThanOrEqual(5, (float) $result->gpa);
            $this->assertSame($result->is_pass, $result->failed_count === 0);
            $this->assertSame($result->is_pass, $result->grade !== 'F');
        }

        $this->actingAs($admin, 'sanctum')->postJson("/api/exams/{$exam->id}/publish")->assertOk();
    }

    public function test_it_does_nothing_without_the_prerequisites(): void
    {
        $this->seed(ExamSeeder::class);

        $this->assertSame(0, Exam::count());
    }
}
