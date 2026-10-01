<?php

namespace Tests\Feature;

use App\Models\ExamResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddPassedCountToExamResultsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_backfill_counts_graded_units_that_are_not_an_f(): void
    {
        $result = ExamResult::factory()->create([
            'passed_count' => 0,
            'subjects' => [
                ['grade' => 'A+', 'is_optional' => false],
                ['grade' => 'F', 'is_optional' => false],
                ['grade' => 'B', 'is_optional' => false],
                ['grade' => 'C', 'is_optional' => true],
            ],
        ]);
        $none = ExamResult::factory()->create(['passed_count' => 0, 'subjects' => [['grade' => 'F']]]);

        $migration = require database_path('migrations/2026_10_07_000001_add_passed_count_to_exam_results.php');
        $migration->down();
        $migration->up();

        $this->assertSame(3, (int) DB::table('exam_results')->where('id', $result->id)->value('passed_count'));
        $this->assertSame(0, (int) DB::table('exam_results')->where('id', $none->id)->value('passed_count'));
    }
}
