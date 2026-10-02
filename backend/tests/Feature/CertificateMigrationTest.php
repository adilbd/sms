<?php

namespace Tests\Feature;

use App\Models\Certificate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CertificateMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_tables_have_their_columns_and_roll_back_and_migrate_again(): void
    {
        $this->assertTrue(Schema::hasColumns('certificates', [
            'type', 'serial_no', 'student_id', 'enrolment_id', 'academic_year_id', 'issued_on', 'issued_by',
            'data', 'cancelled_at', 'cancelled_by', 'cancel_reason',
        ]));
        $this->assertTrue(Schema::hasColumns('certificate_counters', ['type', 'year', 'last_number']));

        Certificate::factory()->create();

        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('certificates'));
        $this->assertFalse(Schema::hasTable('certificate_counters'));

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('certificates'));
        $this->assertTrue(Schema::hasTable('certificate_counters'));
    }
}
