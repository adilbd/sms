<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\StudentEnrolment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EnrolledOnMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_a_nullable_date_column_and_rolls_back(): void
    {
        $this->assertTrue(Schema::hasColumn('student_enrolments', 'enrolled_on'));

        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('student_enrolments', 'enrolled_on'));

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('student_enrolments', 'enrolled_on'));
    }

    public function test_the_backfill_uses_the_dhaka_date_of_creation_or_the_year_start_if_later(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1])->assertSuccessful();

        $year = AcademicYear::factory()->create(['year' => 2026]);
        $future = AcademicYear::factory()->create(['year' => 2027]);

        $make = function (AcademicYear $academicYear, ?string $createdAt) {
            $id = StudentEnrolment::factory()->create(['academic_year_id' => $academicYear->id, 'student_id' => Student::factory()])->id;
            DB::table('student_enrolments')->where('id', $id)->update(['created_at' => $createdAt]);

            return $id;
        };

        // 2026-03-09 20:30 UTC is already 2026-03-10 02:30 in Dhaka (UTC+6).
        $midYear = $make($year, '2026-03-09 20:30:00');
        $early = $make($year, '2025-11-20 10:00:00');
        $advance = $make($future, '2026-10-01 05:00:00');
        $noTimestamp = $make($year, null);

        $this->artisan('migrate')->assertSuccessful();

        $on = fn (int $id) => DB::table('student_enrolments')->where('id', $id)->value('enrolled_on');
        $this->assertSame('2026-03-10', $on($midYear));
        $this->assertSame('2026-01-01', $on($early));
        $this->assertSame('2027-01-01', $on($advance));
        $this->assertSame('2026-01-01', $on($noTimestamp));
    }
}
