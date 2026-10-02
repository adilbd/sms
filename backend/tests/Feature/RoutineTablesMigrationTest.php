<?php

namespace Tests\Feature;

use App\Models\Period;
use App\Models\RoutineSlot;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RoutineTablesMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_migration_creates_the_tables_and_rolls_back_and_runs_again(): void
    {
        $this->assertTrue(Schema::hasTable('periods'));
        $this->assertTrue(Schema::hasTable('routine_slots'));
        $this->assertTrue(Schema::hasColumns('routine_slots', ['academic_year_id', 'section_id', 'day', 'period_id', 'subject_id', 'staff_id', 'room', 'room_key']));

        // Data in both tables doesn't stop the rollback: slots are dropped before periods.
        RoutineSlot::factory()->create();

        $this->artisan('migrate:rollback', ['--step' => 2])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('routine_slots'));
        $this->assertFalse(Schema::hasTable('periods'));

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasTable('periods'));
        $this->assertTrue(Schema::hasTable('routine_slots'));
    }

    public function test_the_unique_keys_hold(): void
    {
        $period = Period::factory()->create(['number' => 1]);

        try {
            Period::factory()->create(['shift_id' => $period->shift_id, 'number' => 1]);
            $this->fail('A period number must be unique per shift.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }

        $slot = RoutineSlot::factory()->create(['period_id' => $period->id]);

        try {
            RoutineSlot::factory()->create([
                'academic_year_id' => $slot->academic_year_id, 'section_id' => $slot->section_id, 'day' => $slot->day, 'period_id' => $period->id,
            ]);
            $this->fail('A cell must be unique per year, section, day and period.');
        } catch (UniqueConstraintViolationException) {
            $this->addToAssertionCount(1);
        }
    }
}
