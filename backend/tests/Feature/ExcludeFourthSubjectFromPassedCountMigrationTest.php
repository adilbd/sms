<?php

namespace Tests\Feature;

use App\Models\ExamResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ExcludeFourthSubjectFromPassedCountMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_recompute_excludes_optional_units(): void
    {
        $passedFourth = ExamResult::factory()->create([
            'passed_count' => 4,
            'subjects' => [
                ['grade' => 'A+', 'is_optional' => false],
                ['grade' => 'F', 'is_optional' => false],
                ['grade' => 'B', 'is_optional' => false],
                ['grade' => 'C', 'is_optional' => true],
            ],
        ]);
        $failedFourth = ExamResult::factory()->create([
            'passed_count' => 2,
            'subjects' => [
                ['grade' => 'A', 'is_optional' => false],
                ['grade' => 'B', 'is_optional' => false],
                ['grade' => 'F', 'is_optional' => true],
            ],
        ]);

        $migration = require database_path('migrations/2026_10_08_000001_exclude_the_4th_subject_from_passed_count.php');
        $migration->up();

        $this->assertSame(2, (int) DB::table('exam_results')->where('id', $passedFourth->id)->value('passed_count'));
        $this->assertSame(2, (int) DB::table('exam_results')->where('id', $failedFourth->id)->value('passed_count'));
    }
}
