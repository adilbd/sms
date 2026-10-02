<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Subject;
use App\Models\SubjectAssignment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

class ExamSetupMigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_backfill_sets_written_marks_from_the_subject(): void
    {
        $subject = Subject::factory()->create(['total_marks' => 80, 'pass_marks' => 30]);
        $row = ClassSubject::factory()->create(['subject_id' => $subject->id]);
        ClassSubject::whereKey($row->id)->update(['written_full' => null, 'written_pass' => null]);

        $migration = require database_path('migrations/2026_10_03_000001_add_marks_scheme_to_class_subjects_table.php');
        (new ReflectionMethod($migration, 'backfillWrittenMarks'))->invoke($migration);

        $row->refresh();
        $this->assertSame(80, $row->written_full);
        $this->assertSame(30, $row->written_pass);
        $this->assertNull($row->mcq_full);
        $this->assertNull($row->paper_group);
    }

    public function test_the_unique_key_is_one_teacher_per_section_subject_year(): void
    {
        $section = Section::factory()->create();
        $subject = Subject::factory()->create();
        $year = AcademicYear::factory()->create();
        $base = ['class_id' => $section->class_id, 'section_id' => $section->id, 'subject_id' => $subject->id, 'academic_year_id' => $year->id];

        SubjectAssignment::create([...$base, 'staff_id' => Staff::factory()->create()->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        SubjectAssignment::create([...$base, 'staff_id' => Staff::factory()->create()->id]);
    }

    public function test_migrations_roll_back_and_re_run(): void
    {
        // Step back past every migration after the one that added class_subjects.paper_group (the
        // exam tables, results, staff logins, attendance, enrolled_on, choice_group and fee ones); raise it when a migration is added.
        $this->artisan('migrate:rollback', ['--step' => 14])->assertSuccessful();

        $this->assertFalse(Schema::hasColumn('class_subjects', 'paper_group'));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasColumn('class_subjects', 'paper_group'));
        $this->assertSame(0, DB::table('subject_assignments')->count());
    }
}
