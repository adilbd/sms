<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AttendanceMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_new_tables_have_the_enrolment_based_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('attendances', [
            'student_id', 'enrolment_id', 'section_id', 'academic_year_id', 'date', 'status', 'remarks', 'marked_by',
        ]));
        $this->assertFalse(Schema::hasColumn('attendances', 'class_id'));
        $this->assertTrue(Schema::hasColumns('holidays', ['date', 'name_en', 'name_bn', 'academic_year_id']));
    }

    public function test_the_migration_rolls_back_to_the_old_structure_and_runs_again(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 2])->assertSuccessful();

        $this->assertFalse(Schema::hasTable('holidays'));
        $this->assertTrue(Schema::hasColumns('attendances', ['student_id', 'class_id', 'section_id', 'date', 'status', 'marked_by']));
        $this->assertFalse(Schema::hasColumn('attendances', 'enrolment_id'));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('holidays'));
        $this->assertTrue(Schema::hasColumn('attendances', 'enrolment_id'));
        $this->assertFalse(Schema::hasColumn('attendances', 'class_id'));
    }
}
