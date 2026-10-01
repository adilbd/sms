<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Holiday;
use Carbon\Carbon;
use Database\Seeders\AcademicYearSeeder;
use Database\Seeders\AttendanceSeeder;
use Database\Seeders\ClassSeeder;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\HolidaySeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SectionSeeder;
use Database\Seeders\ShiftSeeder;
use Database\Seeders\StudentSeeder;
use Database\Seeders\SubjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceSeedersTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function seedPrerequisites(): void
    {
        $this->seed([
            RolePermissionSeeder::class, ShiftSeeder::class, ClassSeeder::class, SubjectSeeder::class,
            CurriculumSeeder::class, AcademicYearSeeder::class, SectionSeeder::class,
        ]);
    }

    public function test_the_holiday_seeder_adds_the_fixed_date_holidays_once(): void
    {
        $this->seed(AcademicYearSeeder::class);

        $this->seed(HolidaySeeder::class);
        $this->seed(HolidaySeeder::class);

        $this->assertSame(6, Holiday::count());
        foreach (['2026-02-21', '2026-03-26', '2026-05-01', '2026-12-16'] as $date) {
            $this->assertDatabaseHas('holidays', ['date' => $date]);
        }
        $this->assertSame('Victory Day', Holiday::where('date', '2026-12-16')->value('name_en'));
        $this->assertNotNull(Holiday::where('date', '2026-02-21')->value('name_bn'));
    }

    public function test_the_holiday_seeder_does_nothing_without_the_2026_year(): void
    {
        $this->seed(HolidaySeeder::class);

        $this->assertSame(0, Holiday::count());
    }

    public function test_the_attendance_seeder_marks_the_last_five_school_days_and_never_duplicates(): void
    {
        // Thursday 2026-10-08 in Dhaka.
        Carbon::setTestNow(Carbon::parse('2026-10-07 20:30:00', 'UTC'));
        $this->seedPrerequisites();
        $this->seed([HolidaySeeder::class, StudentSeeder::class]);

        $this->seed(AttendanceSeeder::class);
        $first = Attendance::count();
        $statuses = Attendance::orderBy('id')->pluck('status', 'id')->all();
        $this->seed(AttendanceSeeder::class);

        // 60 seeded students (Section A, Morning shift) x 5 school days.
        $this->assertSame(300, $first);
        $this->assertSame($first, Attendance::count());
        $this->assertSame($statuses, Attendance::orderBy('id')->pluck('status', 'id')->all());

        // Thursday the 8th back to Sunday the 4th: Saturday is a school day, Friday the 2nd is not reached.
        $this->assertEqualsCanonicalizing(
            ['2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'],
            Attendance::distinct()->pluck('date')->all(),
        );
        $this->assertContains('absent', $statuses);
        $this->assertContains('late', $statuses);
        $this->assertContains('present', $statuses);
        $this->assertSame(1, Attendance::whereNotNull('marked_by')->distinct()->count('marked_by'));
    }
}
