<?php

namespace Tests\Feature;

use App\Models\Homework;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HomeworkMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_table_has_its_columns_and_rolls_back_cleanly(): void
    {
        $this->assertTrue(Schema::hasColumns('homework', [
            'academic_year_id', 'section_id', 'subject_id', 'staff_id', 'title', 'details', 'assigned_on', 'due_on',
            'attachment_path', 'attachment_name', 'deleted_at',
        ]));

        // Rows don't stop the rollback.
        Homework::factory()->create();

        $this->artisan('migrate:rollback', ['--step' => 2])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('homework'));

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('homework'));
    }
}
