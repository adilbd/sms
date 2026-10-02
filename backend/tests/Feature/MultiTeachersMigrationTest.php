<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\ClassSection;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Subject;
use App\Models\SubjectAssignment;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MultiTeachersMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_is_main_and_rolls_back_and_runs_again(): void
    {
        $this->assertTrue(Schema::hasColumn('class_sections', 'is_main'));

        $this->artisan('migrate:rollback', ['--step' => 2])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('class_sections', 'is_main'));

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('class_sections', 'is_main'));
    }

    public function test_the_backfill_makes_every_existing_class_teacher_the_main_one(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 2])->assertSuccessful();

        $year = AcademicYear::factory()->create();
        $first = Section::factory()->create();
        $second = Section::factory()->create();

        foreach ([$first, $second] as $section) {
            ClassSection::factory()->create([
                'class_id' => $section->class_id,
                'section_id' => $section->id,
                'academic_year_id' => $year->id,
                'staff_id' => Staff::factory()->create()->id,
            ]);
        }

        $this->artisan('migrate')->assertSuccessful();

        $this->assertSame(2, DB::table('class_sections')->where('is_main', true)->count());
        $this->assertSame(0, DB::table('class_sections')->where('is_main', false)->count());
    }

    public function test_the_old_unique_keys_are_restored_by_the_rollback(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 2])->assertSuccessful();

        $year = AcademicYear::factory()->create();
        $section = Section::factory()->create();
        $other = Section::factory()->create();
        $staff = Staff::factory()->create();
        $row = fn (Section $s, Staff $t) => [
            'class_id' => $s->class_id, 'section_id' => $s->id, 'academic_year_id' => $year->id, 'staff_id' => $t->id,
        ];

        ClassSection::create($row($section, $staff));

        // A second teacher for the section, and the same teacher in a second section.
        try {
            ClassSection::create($row($section, Staff::factory()->create()));
            $this->fail('A section must have one class teacher per year again.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        try {
            ClassSection::create($row($other, $staff));
            $this->fail('A teacher must lead one section per year again.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        $subject = Subject::factory()->create();
        $assignment = ['class_id' => $section->class_id, 'section_id' => $section->id, 'subject_id' => $subject->id, 'academic_year_id' => $year->id];
        SubjectAssignment::create([...$assignment, 'staff_id' => $staff->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        SubjectAssignment::create([...$assignment, 'staff_id' => Staff::factory()->create()->id]);
    }

    public function test_the_rollback_fails_clearly_while_duplicates_exist(): void
    {
        $year = AcademicYear::factory()->create();
        $section = Section::factory()->create();

        foreach ([true, false] as $isMain) {
            ClassSection::factory()->create([
                'class_id' => $section->class_id,
                'section_id' => $section->id,
                'academic_year_id' => $year->id,
                'staff_id' => Staff::factory()->create()->id,
                'is_main' => $isMain,
            ]);
        }

        $this->expectException(UniqueConstraintViolationException::class);

        $this->artisan('migrate:rollback', ['--step' => 2]);
    }
}
