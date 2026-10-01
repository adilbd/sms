<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use App\Services\EnrolmentService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * EnrolmentService against the real database: capacity, roll numbers, moving a student
 * within a year and the status mirror. The HTTP-level rules are in StudentApiTest.
 */
class StudentEnrolmentTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    private Section $section;

    protected function setUp(): void
    {
        parent::setUp();

        $this->year = AcademicYear::factory()->active()->create();
        $this->section = Section::factory()->create([
            'class_id' => Classes::factory()->create(['number' => 6])->id,
            'capacity' => 2,
        ]);
    }

    private function service(): EnrolmentService
    {
        return app(EnrolmentService::class);
    }

    public function test_enrolments_take_seats_until_the_capacity_is_reached(): void
    {
        $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id]);
        $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id]);

        try {
            $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id]);
            $this->fail('The section should be full.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('enrolment.section_id', $e->errors());
        }

        $this->assertSame(2, StudentEnrolment::count());
    }

    public function test_a_new_enrolment_starts_on_the_admission_date_clamped_to_the_year(): void
    {
        $year = AcademicYear::factory()->create(['year' => 2026]);
        $this->section->update(['capacity' => 10]);
        $make = fn (string $admitted) => $this->service()->save(
            Student::factory()->create(['admission_date' => $admitted]),
            $year,
            ['section_id' => $this->section->id],
        );

        $this->assertSame('2026-01-05', $make('2026-01-05')->enrolled_on->toDateString());
        $this->assertSame('2026-01-01', $make('2024-06-01')->enrolled_on->toDateString());
        $this->assertSame('2026-12-31', $make('2027-02-01')->enrolled_on->toDateString());
    }

    public function test_enrolling_into_a_past_year_after_it_ended_never_starts_after_its_end(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2027-05-01 06:00:00');
        $past = AcademicYear::factory()->create(['year' => 2025]);
        $student = Student::factory()->create(['admission_date' => '2025-02-02']);

        $enrolment = $this->service()->save($student, $past, ['section_id' => $this->section->id]);
        $this->assertSame('2025-02-02', $enrolment->enrolled_on->toDateString());

        // A resave keeps the date; an explicit start (promotion) wins.
        $again = $this->service()->save($student, $past, ['section_id' => $this->section->id, 'roll_number' => 3]);
        $this->assertSame('2025-02-02', $again->enrolled_on->toDateString());

        $other = $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id, 'enrolled_on' => '2026-01-01']);
        $this->assertSame('2026-01-01', $other->enrolled_on->toDateString());

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_a_seat_frees_up_when_a_student_leaves(): void
    {
        $leaver = Student::factory()->create();
        $this->service()->save($leaver, $this->year, ['section_id' => $this->section->id]);
        $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id]);

        $leaver->update(['status' => Student::STATUS_LEFT, 'leaving_date' => '2026-06-30']);
        $this->service()->syncStatus($leaver, $this->year);

        $this->assertSame('left', StudentEnrolment::where('student_id', $leaver->id)->value('status'));
        $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id]);
        $this->assertSame(3, StudentEnrolment::count());
    }

    public function test_the_capacity_counts_per_academic_year(): void
    {
        $next = AcademicYear::factory()->create();
        $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id]);
        $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id]);

        $this->service()->save(Student::factory()->create(), $next, ['section_id' => $this->section->id]);

        $this->assertSame(1, StudentEnrolment::where('academic_year_id', $next->id)->count());
    }

    public function test_a_student_keeping_their_seat_can_be_edited_when_the_section_is_full_or_inactive(): void
    {
        $student = Student::factory()->create();
        $this->service()->save($student, $this->year, ['section_id' => $this->section->id, 'roll_number' => 1]);
        $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id, 'roll_number' => 2]);
        $this->section->update(['is_active' => false]);

        $saved = $this->service()->save($student, $this->year, ['section_id' => $this->section->id, 'roll_number' => 5]);

        $this->assertSame(5, $saved->roll_number);
        $this->assertSame(1, StudentEnrolment::where('student_id', $student->id)->count());
    }

    public function test_a_student_keeps_their_own_roll_number_on_resave(): void
    {
        $student = Student::factory()->create();
        $this->service()->save($student, $this->year, ['section_id' => $this->section->id, 'roll_number' => 1]);

        $saved = $this->service()->save($student, $this->year, ['section_id' => $this->section->id, 'roll_number' => 1]);

        $this->assertSame(1, $saved->roll_number);
    }

    public function test_moving_to_another_class_updates_the_class_from_the_section(): void
    {
        $student = Student::factory()->create();
        $first = $this->service()->save($student, $this->year, ['section_id' => $this->section->id]);
        $other = Section::factory()->create(['class_id' => Classes::factory()->create(['number' => 7])->id]);

        $moved = $this->service()->save($student, $this->year, ['section_id' => $other->id]);

        $this->assertSame($first->id, $moved->id);
        $this->assertSame($other->class_id, $moved->class_id);
    }

    public function test_a_roll_number_clash_is_a_validation_error_per_section_and_year(): void
    {
        $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id, 'roll_number' => 1]);

        try {
            $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id, 'roll_number' => 1]);
            $this->fail('The roll number should be taken.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('enrolment.roll_number', $e->errors());
        }

        // The same roll number in another year is fine.
        $next = AcademicYear::factory()->create();
        $saved = $this->service()->save(Student::factory()->create(), $next, ['section_id' => $this->section->id, 'roll_number' => 1]);
        $this->assertSame(1, $saved->roll_number);
    }

    public function test_the_database_also_enforces_roll_numbers_and_one_enrolment_per_year(): void
    {
        $one = Student::factory()->create();
        $two = Student::factory()->create();
        $attributes = ['academic_year_id' => $this->year->id, 'section_id' => $this->section->id, 'class_id' => $this->section->class_id];
        StudentEnrolment::create($attributes + ['student_id' => $one->id, 'roll_number' => 4]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        StudentEnrolment::create($attributes + ['student_id' => $two->id, 'roll_number' => 4]);
    }

    public function test_history_lists_enrolments_newest_year_first(): void
    {
        $student = Student::factory()->create();
        $old = AcademicYear::factory()->create(['year' => 2024]);
        $this->service()->save($student, $old, ['section_id' => $this->section->id]);
        $this->service()->save($student, $this->year, ['section_id' => $this->section->id]);

        $history = $this->service()->history($student);

        $this->assertInstanceOf(Collection::class, $history);
        $this->assertSame([$this->year->id, $old->id], $history->pluck('academic_year_id')->all());
    }

    public function test_the_admin_can_read_the_history_over_http(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $student = Student::factory()->create();
        $this->service()->save($student, $this->year, ['section_id' => $this->section->id, 'roll_number' => 9]);

        $this->actingAs(User::where('email', 'admin@sms.com')->firstOrFail(), 'sanctum')
            ->getJson("/api/students/{$student->id}/enrolments")
            ->assertOk()
            ->assertJsonPath('data.0.roll_number', 9);
    }

    public function test_a_soft_deleted_student_holds_no_seat_or_roll_number(): void
    {
        $gone = Student::factory()->create();
        $this->service()->save($gone, $this->year, ['section_id' => $this->section->id, 'roll_number' => 1]);
        $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id, 'roll_number' => 2]);
        $this->service()->release($gone, $this->year);
        $gone->delete();

        $saved = $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id, 'roll_number' => 1]);

        $this->assertSame(1, $saved->roll_number);
    }

    public function test_a_student_who_is_not_active_is_not_blocked_by_a_full_section(): void
    {
        $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id]);
        $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id]);

        $left = Student::factory()->create(['status' => Student::STATUS_LEFT, 'leaving_date' => '2026-06-30']);
        $saved = $this->service()->save($left, $this->year, ['section_id' => $this->section->id]);

        $this->assertSame('left', $saved->status);
    }

    public function test_reactivating_a_student_into_a_full_section_is_checked(): void
    {
        $left = Student::factory()->create(['status' => Student::STATUS_LEFT, 'leaving_date' => '2026-06-30']);
        $this->service()->save($left, $this->year, ['section_id' => $this->section->id]);
        $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id]);
        $this->service()->save(Student::factory()->create(), $this->year, ['section_id' => $this->section->id]);

        $left->update(['status' => Student::STATUS_ACTIVE, 'leaving_date' => null]);

        try {
            $this->service()->save($left, $this->year, ['section_id' => $this->section->id]);
            $this->fail('The section should be full.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('enrolment.section_id', $e->errors());
        }
    }
}
