<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Section;
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

        $this->artisan('migrate:rollback', ['--step' => 4])->assertSuccessful();
        $this->assertFalse(Schema::hasColumn('student_enrolments', 'enrolled_on'));

        $this->artisan('migrate')->assertSuccessful();
        $this->assertTrue(Schema::hasColumn('student_enrolments', 'enrolled_on'));
    }

    public function test_the_backfill_uses_the_admission_date_clamped_to_the_year(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 4])->assertSuccessful();

        $year = AcademicYear::factory()->create(['year' => 2026]);
        $future = AcademicYear::factory()->create(['year' => 2027]);

        $section = Section::factory()->create();
        $make = function (AcademicYear $academicYear, string $admitted, ?string $createdAt) use ($section) {
            $id = StudentEnrolment::factory()->create([
                'section_id' => $section->id,
                'class_id' => $section->class_id,
                'academic_year_id' => $academicYear->id,
                'student_id' => Student::factory()->create(['admission_date' => $admitted]),
            ])->id;
            DB::table('student_enrolments')->where('id', $id)->update(['created_at' => $createdAt]);

            return $id;
        };

        // Created when the database was seeded, long after the student joined: created_at is ignored.
        $joinedJan = $make($year, '2026-01-05', '2026-10-01 05:00:00');
        $midYear = $make($year, '2026-03-10', null);
        $earlier = $make($year, '2024-06-01', '2026-10-01 05:00:00');
        $afterEnd = $make($year, '2027-02-01', '2026-02-01 05:00:00');
        $advance = $make($future, '2026-05-01', '2026-10-01 05:00:00');

        $this->artisan('migrate')->assertSuccessful();

        $on = fn (int $id) => DB::table('student_enrolments')->where('id', $id)->value('enrolled_on');
        $this->assertSame('2026-01-05', $on($joinedJan));
        $this->assertSame('2026-03-10', $on($midYear));
        $this->assertSame('2026-01-01', $on($earlier));
        $this->assertSame('2026-12-31', $on($afterEnd));
        $this->assertSame('2027-01-01', $on($advance));
    }
}
