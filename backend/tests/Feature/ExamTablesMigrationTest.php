<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExamTablesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_exam_tables_exist_and_the_old_ones_are_gone(): void
    {
        foreach (['exams', 'exam_subjects', 'exam_marks', 'exam_results'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        $this->assertFalse(Schema::hasTable('exam_schedules'));
        $this->assertTrue(Schema::hasColumns('exam_results', ['exam_id', 'enrolment_id', 'gpa', 'grade', 'is_pass', 'class_position', 'section_position', 'subjects']));
        $this->assertTrue(Schema::hasColumns('exams', ['name_en', 'name_bn', 'status', 'published_at', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('exam_subjects', ['paper_group', 'written_full', 'practical_pass', 'exam_date', 'start_time', 'sort_order']));
        $this->assertTrue(Schema::hasColumns('exam_marks', ['enrolment_id', 'written', 'mcq', 'practical', 'is_absent', 'entered_by']));
    }

    public function test_the_migrations_roll_back_to_the_old_structure_and_run_again(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 12])->assertSuccessful();

        foreach (['exam_marks', 'exam_subjects'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        // down() restores the old empty tables.
        foreach (['exams', 'exam_schedules', 'exam_results'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }

        // The new results table is gone; the old (differently shaped) one is back.
        $this->assertFalse(Schema::hasColumn('exam_results', 'subjects'));
        $this->assertTrue(Schema::hasColumn('exams', 'is_published'));
        $this->assertFalse(Schema::hasColumn('exams', 'name_en'));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('exam_marks'));
        $this->assertTrue(Schema::hasColumn('exam_results', 'subjects'));
        $this->assertFalse(Schema::hasTable('exam_schedules'));
        $this->assertTrue(Schema::hasColumn('exams', 'name_en'));
    }
}
