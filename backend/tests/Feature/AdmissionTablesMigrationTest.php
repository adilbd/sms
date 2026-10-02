<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdmissionTablesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_admission_tables_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('admission_rounds', ['academic_year_id', 'name_en', 'name_bn', 'opens_at', 'closes_at', 'is_published', 'instructions_bn', 'instructions_en', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('admission_round_classes', ['round_id', 'class_id', 'seats']));
        $this->assertTrue(Schema::hasColumns('admission_applications', ['application_no', 'round_id', 'class_id', 'group', 'shift_id', 'birth_registration_number', 'photo_path', 'birth_certificate_path', 'previous_school_doc_path', 'status', 'test_at', 'test_venue', 'test_score', 'admin_note', 'decided_by', 'decided_at', 'student_id', 'submitted_ip_hash', 'deleted_at']));
        $this->assertTrue(Schema::hasColumns('admission_application_counters', ['year', 'last_number']));
    }

    public function test_the_migration_rolls_back_and_runs_again(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 3])->assertSuccessful();

        foreach (['admission_rounds', 'admission_round_classes', 'admission_applications', 'admission_application_counters'] as $table) {
            $this->assertFalse(Schema::hasTable($table), $table);
        }

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('admission_applications'));
    }

    public function test_index_names_fit_mysqls_64_character_limit(): void
    {
        foreach (['admission_rounds', 'admission_round_classes', 'admission_applications'] as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                $this->assertLessThanOrEqual(64, strlen($index['name']), $index['name']);
            }
        }
    }
}
