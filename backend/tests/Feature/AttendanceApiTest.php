<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\ClassSection;
use App\Models\Holiday;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExams;
use Tests\TestCase;

/**
 * Daily attendance by section (docs/tasks/attendance.md). Time is frozen at 02:30 on
 * Thursday 2026-10-08 in Asia/Dhaka, which is still Wednesday 2026-10-07 in UTC, so every
 * "today" below also proves the date comes from Dhaka. Friday is the weekly holiday.
 */
class AttendanceApiTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private const TODAY = '2026-10-08';

    private StudentEnrolment $rahim;

    private StudentEnrolment $karim;

    private StudentEnrolment $salma;

    private User $classTeacher;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-07 20:30:00', 'UTC'));

        $this->setUpExams();

        // Rolls 1, 2 and 3, entered out of order to prove the sheet sorts by roll.
        $this->karim = $this->enrol($this->section9, 'science', null, 2, ['name_en' => 'Karim']);
        $this->rahim = $this->enrol($this->section9, 'science', null, 1, ['name_en' => 'Rahim']);
        $this->salma = $this->enrol($this->section9, 'science', null, 3, ['name_en' => 'Salma']);

        $this->classTeacher = $this->classTeacherOf($this->section9);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function classTeacherOf(Section $section): User
    {
        $user = $this->userWithRole('teacher');
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $staff->shifts()->attach($section->shift_id);

        ClassSection::create([
            'class_id' => $section->class_id, 'section_id' => $section->id,
            'academic_year_id' => $this->year->id, 'staff_id' => $staff->id,
        ]);

        return $user;
    }

    private function mark(StudentEnrolment $enrolment, string $date, string $status): Attendance
    {
        return Attendance::factory()->create(['enrolment_id' => $enrolment->id, 'date' => $date, 'status' => $status]);
    }

    private function entries(string $status = 'present'): array
    {
        return array_map(
            fn (StudentEnrolment $e) => ['student_id' => $e->student_id, 'status' => $status],
            [$this->rahim, $this->karim, $this->salma]
        );
    }

    private function save(array $payload, ?User $as = null)
    {
        return $this->as($as ?? $this->classTeacher)->putJson('/api/attendance/sheet', [
            'section_id' => $this->section9->id, ...$payload,
        ]);
    }

    private function sheet(string $query = '', ?User $as = null)
    {
        return $this->as($as ?? $this->classTeacher)->getJson("/api/attendance/sheet?section_id={$this->section9->id}{$query}");
    }

    // --- the sheet ---------------------------------------------------------------------

    public function test_the_sheet_defaults_to_today_in_dhaka_and_lists_active_enrolments_by_roll(): void
    {
        $this->enrol($this->section9, 'science', null, 4)->update(['status' => StudentEnrolment::STATUS_LEFT]);
        $this->enrol($this->section9, 'science', null, 5)->student->delete();
        $this->enrol(Section::factory()->create(['class_id' => $this->class9->id, 'shift_id' => $this->shift->id]), null, null, 1);

        $response = $this->sheet()
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'date', 'academic_year_id', 'is_holiday', 'holiday',
                'section' => ['id', 'class_id', 'name', 'code', 'group'],
                'students' => [['student_id', 'enrolment_id', 'student_code', 'name_en', 'name_bn', 'roll_number', 'status', 'remarks', 'marked_by']],
            ]])
            ->assertJsonPath('data.date', self::TODAY)
            ->assertJsonPath('data.is_holiday', false)
            ->assertJsonPath('data.holiday', null)
            ->assertJsonPath('data.students.0.status', null);

        $this->assertSame(['Rahim', 'Karim', 'Salma'], array_column($response->json('data.students'), 'name_en'));
        $this->assertSame([1, 2, 3], array_column($response->json('data.students'), 'roll_number'));
    }

    public function test_saving_present_absent_late_and_leave_then_reloading_shows_them_with_marked_by(): void
    {
        $this->save(['entries' => [
            ['student_id' => $this->rahim->student_id, 'status' => 'present'],
            ['student_id' => $this->karim->student_id, 'status' => 'absent', 'remarks' => 'Fever'],
            ['student_id' => $this->salma->student_id, 'status' => 'late'],
        ]])
            ->assertOk()
            ->assertJsonPath('message', 'Attendance saved successfully')
            ->assertJsonPath('data.date', self::TODAY)
            ->assertJsonPath('data.students.0.status', 'present')
            ->assertJsonPath('data.students.1.status', 'absent')
            ->assertJsonPath('data.students.1.remarks', 'Fever')
            ->assertJsonPath('data.students.2.status', 'late');

        $this->sheet()
            ->assertOk()
            ->assertJsonPath('data.students.1.status', 'absent')
            ->assertJsonPath('data.students.1.remarks', 'Fever')
            ->assertJsonPath('data.students.1.marked_by', $this->classTeacher->id)
            ->assertJsonPath('data.students.2.marked_by', $this->classTeacher->id);

        $this->assertDatabaseHas('attendances', [
            'enrolment_id' => $this->karim->id, 'date' => self::TODAY, 'status' => 'absent',
            'section_id' => $this->section9->id, 'academic_year_id' => $this->year->id,
            'student_id' => $this->karim->student_id, 'marked_by' => $this->classTeacher->id,
        ]);

        // Leave is the fourth status.
        $this->save(['entries' => [['student_id' => $this->rahim->student_id, 'status' => 'leave']]])
            ->assertOk()
            ->assertJsonPath('data.students.0.status', 'leave');
    }

    public function test_saving_the_same_day_again_updates_rows_and_leaves_students_not_sent_unchanged(): void
    {
        $this->save(['entries' => $this->entries('absent')])->assertOk();

        // Only Karim is sent: Rahim and Salma keep their absent mark.
        $this->save(['entries' => [['student_id' => $this->karim->student_id, 'status' => 'present', 'remarks' => 'Came late by bus']]])
            ->assertOk();

        $this->assertSame(3, Attendance::where('date', self::TODAY)->count());
        $this->assertSame('present', Attendance::where('enrolment_id', $this->karim->id)->value('status'));
        $this->assertSame('absent', Attendance::where('enrolment_id', $this->rahim->id)->value('status'));
        $this->assertSame('absent', Attendance::where('enrolment_id', $this->salma->id)->value('status'));

        // Remarks are cleared when a re-save sends none.
        $this->save(['entries' => [['student_id' => $this->karim->student_id, 'status' => 'present']]])->assertOk();
        $this->assertNull(Attendance::where('enrolment_id', $this->karim->id)->value('remarks'));
    }

    public function test_an_admin_can_edit_a_date_twenty_days_ago_but_a_teacher_cannot(): void
    {
        // Thursday 2026-09-17, 21 days before today.
        $this->save(['date' => '2026-09-17', 'entries' => $this->entries('late')], $this->admin)
            ->assertOk()
            ->assertJsonPath('data.date', '2026-09-17')
            ->assertJsonPath('data.students.0.status', 'late')
            ->assertJsonPath('data.students.0.marked_by', $this->admin->id);

        $this->save(['date' => '2026-09-17', 'entries' => $this->entries()])
            ->assertUnprocessable()->assertJsonValidationErrors(['date']);
    }

    public function test_the_teacher_edit_window_is_today_and_the_six_days_before(): void
    {
        // Thursday 2026-10-01 is the 8th day back; Saturday 2026-10-03 is a school day inside the window.
        $this->save(['date' => '2026-10-01', 'entries' => $this->entries()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);
        $this->assertSame(0, Attendance::count());

        $this->save(['date' => '2026-10-03', 'entries' => $this->entries()])->assertOk();

        // Friday 2026-10-02 is the oldest day in the window, but a weekly holiday.
        $this->save(['date' => '2026-10-02', 'entries' => $this->entries()])->assertUnprocessable();
    }

    // --- authorization -----------------------------------------------------------------

    public function test_a_teacher_who_is_not_the_class_teacher_gets_403_on_the_sheet_and_the_report(): void
    {
        $other = $this->classTeacherOf(Section::factory()->create(['class_id' => $this->class10->id, 'shift_id' => $this->shift->id]));
        $subjectTeacher = $this->assignedTeacher($this->section9, $this->bangla);

        foreach ([$other, $subjectTeacher] as $teacher) {
            $this->sheet('', $teacher)->assertForbidden();
            $this->save(['entries' => $this->entries()], $teacher)->assertForbidden();
            $this->as($teacher)->getJson("/api/attendance/report?section_id={$this->section9->id}&month=2026-10")->assertForbidden();
            $this->as($teacher)->getJson("/api/attendance/students/{$this->rahim->student_id}?month=2026-10")->assertForbidden();
        }

        $this->assertSame(0, Attendance::count());
    }

    public function test_a_retired_class_teacher_cannot_use_the_sheet(): void
    {
        $this->classTeacher->staff()->update(['status' => Staff::STATUS_RETIRED, 'leaving_date' => '2026-09-01']);

        $this->sheet()->assertForbidden();
    }

    public function test_the_office_role_and_students_and_guardians_cannot_use_attendance(): void
    {
        foreach (['office', 'student', 'parent'] as $role) {
            $user = $this->userWithRole($role);

            $this->sheet('', $user)->assertForbidden();
            $this->save(['entries' => $this->entries()], $user)->assertForbidden();
            $this->as($user)->getJson("/api/attendance/report?section_id={$this->section9->id}")->assertForbidden();
            $this->as($user)->getJson("/api/attendance/students/{$this->rahim->student_id}")->assertForbidden();
        }
    }

    public function test_guests_get_401(): void
    {
        $this->getJson('/api/attendance/sheet')->assertUnauthorized();
        $this->putJson('/api/attendance/sheet', [])->assertUnauthorized();
        $this->getJson('/api/my/attendance')->assertUnauthorized();
    }

    // --- which dates ---------------------------------------------------------------------

    public function test_a_future_date_is_rejected(): void
    {
        $this->save(['date' => '2026-10-09', 'entries' => $this->entries()], $this->admin)
            ->assertUnprocessable()->assertJsonValidationErrors(['date']);
        // Wednesday in UTC but already Thursday in Dhaka: the next day is the future.
        $this->save(['date' => '2026-10-11', 'entries' => $this->entries()], $this->admin)->assertUnprocessable();
    }

    public function test_a_date_outside_the_academic_year_is_rejected(): void
    {
        $this->save(['date' => '2025-10-08', 'entries' => $this->entries()], $this->admin)
            ->assertUnprocessable()->assertJsonValidationErrors(['date']);

        $this->year->update(['start_date' => '2026-02-01']);
        $this->save(['date' => '2026-01-15', 'entries' => $this->entries()], $this->admin)->assertUnprocessable();
        $this->sheet('&date=2025-10-08', $this->admin)->assertUnprocessable()->assertJsonValidationErrors(['date']);
    }

    public function test_an_out_of_year_date_from_a_non_class_teacher_is_403_not_422(): void
    {
        $other = $this->classTeacherOf(Section::factory()->create(['class_id' => $this->class10->id, 'shift_id' => $this->shift->id]));
        $outsider = $this->assignedTeacher($this->section9, $this->bangla);

        foreach ([$other, $outsider] as $teacher) {
            // 2025 has no academic year at all; 2026-01-15 is a year with the date before its start.
            $this->sheet('&date=2025-10-08', $teacher)->assertForbidden();
            $this->save(['date' => '2025-10-08', 'entries' => $this->entries()], $teacher)->assertForbidden();
            $this->as($teacher)->getJson("/api/attendance/report?section_id={$this->section9->id}&month=2027-01")->assertForbidden();
        }

        $this->year->update(['start_date' => '2026-02-01']);
        $this->sheet('&date=2026-01-15', $outsider)->assertForbidden();

        // The class teacher and an admin still get the date error.
        $this->sheet('&date=2025-10-08', $this->classTeacher)->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->sheet('&date=2025-10-08', $this->admin)->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->sheet('&date=2026-01-15', $this->classTeacher)->assertUnprocessable();
    }

    // --- enrolment start ---------------------------------------------------------------

    public function test_a_mid_year_joiners_percentage_only_counts_school_days_since_joining(): void
    {
        // Salma joined on Monday 2026-10-05: school days from then are the 5th to the 8th.
        $this->salma->update(['enrolled_on' => '2026-10-05']);
        $this->mark($this->salma, '2026-10-05', 'present');
        $this->mark($this->salma, '2026-10-06', 'late');
        // Rahim has no start date, so he counts from the first of the month.
        $this->mark($this->rahim, '2026-10-05', 'present');

        $students = $this->sheetReport()->assertOk()->json('data.students');

        $this->assertSame('Salma', $students[2]['student']['name_en']);
        $this->assertSame('50.00', $students[2]['percentage']);
        $this->assertSame('14.29', $students[0]['percentage']);

        // The year-to-date figure of the student endpoint starts at the joining date too.
        $ytd = $this->as($this->classTeacher)->getJson("/api/attendance/students/{$this->salma->student_id}?month=2026-10")
            ->assertOk()->assertJsonPath('data.school_days', ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08'])
            ->assertJsonPath('data.percentage', '50.00')
            ->json('data.year_to_date');
        $this->assertSame(4, $ytd['school_days']);
        $this->assertSame('50.00', $ytd['percentage']);
    }

    public function test_a_student_who_joins_after_the_month_is_not_in_its_report(): void
    {
        $this->salma->update(['enrolled_on' => '2026-10-05']);

        $names = array_column(array_column($this->sheetReport('2026-09')->assertOk()->json('data.students'), 'student'), 'name_en');

        $this->assertSame(['Rahim', 'Karim'], $names);
    }

    public function test_a_past_date_sheet_lists_who_was_enrolled_then_and_includes_students_who_left_later(): void
    {
        $this->salma->update(['enrolled_on' => '2026-10-07']);
        $leaver = $this->enrol($this->section9, 'science', null, 4, ['name_en' => 'Leaver', 'status' => Student::STATUS_LEFT, 'leaving_date' => '2026-10-07']);
        $leaver->update(['status' => StudentEnrolment::STATUS_LEFT]);

        $on = fn (string $date) => array_column($this->sheet("&date={$date}", $this->admin)->assertOk()->json('data.students'), 'name_en');

        $this->assertSame(['Rahim', 'Karim', 'Leaver'], $on('2026-10-06'));
        $this->assertSame(['Rahim', 'Karim', 'Salma', 'Leaver'], $on('2026-10-07'));
        $this->assertSame(['Rahim', 'Karim', 'Salma'], $on('2026-10-08'));

        // A student who is not on the sheet of that date cannot be marked for it.
        $this->save(['date' => '2026-10-06', 'entries' => [['student_id' => $this->salma->student_id, 'status' => 'present']]], $this->admin)
            ->assertUnprocessable()->assertJsonValidationErrors(['entries.0.student_id']);
        $this->save(['date' => '2026-10-06', 'entries' => [['student_id' => $leaver->student_id, 'status' => 'present']]], $this->admin)->assertOk();
    }

    public function test_a_listed_holiday_is_rejected_and_shown_on_the_sheet(): void
    {
        Holiday::factory()->create(['date' => '2026-10-07', 'name_en' => 'Mid-term break', 'academic_year_id' => $this->year->id]);

        $this->save(['date' => '2026-10-07', 'entries' => $this->entries()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);

        $this->sheet('&date=2026-10-07')
            ->assertOk()
            ->assertJsonPath('data.is_holiday', true)
            ->assertJsonPath('data.holiday.type', 'holiday')
            ->assertJsonPath('data.holiday.name_en', 'Mid-term break');
    }

    public function test_friday_is_a_weekly_holiday(): void
    {
        $this->save(['date' => '2026-10-02', 'entries' => $this->entries()], $this->admin)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date']);

        $this->sheet('&date=2026-10-02')
            ->assertOk()
            ->assertJsonPath('data.is_holiday', true)
            ->assertJsonPath('data.holiday.type', 'weekly')
            ->assertJsonPath('data.holiday.name_en', 'Friday');
    }

    public function test_weekly_holidays_set_to_friday_and_saturday_skip_both_days(): void
    {
        // Saturday is a school day until the setting says otherwise.
        $this->save(['date' => '2026-10-03', 'entries' => $this->entries()], $this->admin)->assertOk();

        $this->as($this->admin)->putJson('/api/settings/institute', ['weekly_holidays' => ['friday', 'saturday']])
            ->assertOk()
            ->assertJsonPath('data.weekly_holidays', ['saturday', 'friday']);

        $this->save(['date' => '2026-10-03', 'entries' => $this->entries()], $this->admin)
            ->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->save(['date' => '2026-10-02', 'entries' => $this->entries()], $this->admin)->assertUnprocessable();
        $this->save(['date' => '2026-10-04', 'entries' => $this->entries()], $this->admin)->assertOk();

        // 1, 4, 5, 6, 7 and 8 October: both weekend days are skipped now.
        $this->as($this->admin)->getJson("/api/attendance/report?section_id={$this->section9->id}&month=2026-10")
            ->assertJsonPath('data.school_days', ['2026-10-01', '2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08']);
    }

    // --- the roster and validation -------------------------------------------------------

    public function test_a_student_who_is_not_on_the_sheet_is_rejected_per_row_and_nothing_is_saved(): void
    {
        $stranger = $this->enrol(Section::factory()->create(['class_id' => $this->class9->id, 'shift_id' => $this->shift->id]), null, null, 1);
        $left = $this->enrol($this->section9, 'science', null, 9);
        $left->update(['status' => StudentEnrolment::STATUS_LEFT]);

        $this->save(['entries' => [
            ['student_id' => $this->rahim->student_id, 'status' => 'present'],
            ['student_id' => $stranger->student_id, 'status' => 'present'],
            ['student_id' => $left->student_id, 'status' => 'absent'],
        ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['entries.1.student_id', 'entries.2.student_id'])
            ->assertJsonMissingValidationErrors(['entries.0.student_id']);

        $this->assertSame(0, Attendance::count());
    }

    public function test_the_payload_is_validated(): void
    {
        $this->save([])->assertUnprocessable()->assertJsonValidationErrors(['entries']);
        $this->save(['entries' => [['student_id' => $this->rahim->student_id, 'status' => 'half_day']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['entries.0.status']);
        $this->save(['entries' => [['student_id' => $this->rahim->student_id]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['entries.0.status']);
        $this->save(['entries' => [
            ['student_id' => $this->rahim->student_id, 'status' => 'present'],
            ['student_id' => $this->rahim->student_id, 'status' => 'absent'],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['entries.0.student_id', 'entries.1.student_id']);
        $this->save(['entries' => [['student_id' => $this->rahim->student_id, 'status' => 'present', 'remarks' => str_repeat('x', 256)]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['entries.0.remarks']);
        $this->save(['date' => '2026-02-30', 'entries' => $this->entries()])->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->save(['date' => '08/10/2026', 'entries' => $this->entries()])->assertUnprocessable()->assertJsonValidationErrors(['date']);

        $this->as($this->admin)->putJson('/api/attendance/sheet', ['entries' => $this->entries()])
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id']);
        $this->as($this->admin)->putJson('/api/attendance/sheet', ['section_id' => 999999, 'entries' => $this->entries()])
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id']);
    }

    public function test_query_parameters_are_validated(): void
    {
        $this->as($this->admin)->getJson('/api/attendance/sheet?section_id[]=x')->assertUnprocessable()->assertJsonValidationErrors(['section_id']);
        $this->as($this->admin)->getJson('/api/attendance/sheet')->assertUnprocessable()->assertJsonValidationErrors(['section_id']);
        $this->as($this->admin)->getJson("/api/attendance/sheet?section_id={$this->section9->id}&date[]=x")->assertUnprocessable()->assertJsonValidationErrors(['date']);
        $this->as($this->admin)->getJson('/api/attendance/report?section_id[]=x')->assertUnprocessable()->assertJsonValidationErrors(['section_id']);

        foreach (['2026-13', '2026-1', 'October', '2026-10-01', '202610'] as $month) {
            $this->as($this->admin)->getJson("/api/attendance/report?section_id={$this->section9->id}&month={$month}")
                ->assertUnprocessable()->assertJsonValidationErrors(['month']);
            $this->as($this->admin)->getJson("/api/attendance/students/{$this->rahim->student_id}?month={$month}")
                ->assertUnprocessable()->assertJsonValidationErrors(['month']);
        }

        $this->as($this->admin)->getJson("/api/attendance/report?section_id={$this->section9->id}&month[]=x")->assertUnprocessable();
    }

    // --- reports -------------------------------------------------------------------------

    public function test_the_monthly_report_counts_totals_and_the_percentage_over_school_days_so_far(): void
    {
        // School days so far in October: the 1st, then Saturday 3rd to Thursday 8th (Friday 2nd is a weekly holiday).
        $this->mark($this->rahim, '2026-10-01', 'present');
        $this->mark($this->rahim, '2026-10-03', 'late');
        $this->mark($this->rahim, '2026-10-04', 'absent');
        $this->mark($this->rahim, '2026-10-05', 'leave');
        $this->mark($this->rahim, '2026-10-06', 'present');
        $this->mark($this->karim, '2026-10-01', 'present');
        // A row on a weekly holiday (recorded before the setting changed) is not counted.
        $this->mark($this->karim, '2026-10-02', 'present');

        $response = $this->sheetReport()
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'month', 'school_days',
                'students' => [['student' => ['id', 'student_code', 'name_en', 'name_bn', 'roll_number'], 'days', 'totals' => ['present', 'absent', 'late', 'leave'], 'percentage']],
            ]])
            ->assertJsonPath('data.month', '2026-10')
            ->assertJsonPath('data.school_days', ['2026-10-01', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08']);

        $students = $response->json('data.students');
        $this->assertSame(['Rahim', 'Karim', 'Salma'], array_column(array_column($students, 'student'), 'name_en'));

        // 2 present + 1 late of 7 school days.
        $this->assertSame(['present' => 2, 'absent' => 1, 'late' => 1, 'leave' => 1], $students[0]['totals']);
        $this->assertSame('42.86', $students[0]['percentage']);
        $this->assertSame('absent', $students[0]['days']['2026-10-04']);
        $this->assertCount(5, $students[0]['days']);

        $this->assertSame(['present' => 1, 'absent' => 0, 'late' => 0, 'leave' => 0], $students[1]['totals']);
        $this->assertSame('14.29', $students[1]['percentage']);
        $this->assertArrayNotHasKey('2026-10-02', $students[1]['days']);

        // Nothing marked: an empty object, not a list.
        $this->assertSame('0.00', $students[2]['percentage']);
        $this->assertStringContainsString('"days":{}', $response->getContent());
    }

    private function sheetReport(string $month = '2026-10', ?User $as = null)
    {
        return $this->as($as ?? $this->classTeacher)->getJson("/api/attendance/report?section_id={$this->section9->id}&month={$month}");
    }

    public function test_the_report_month_defaults_to_the_current_dhaka_month(): void
    {
        $this->sheetReport('')->assertJsonPath('data.month', '2026-10');
        $this->as($this->classTeacher)->getJson("/api/attendance/report?section_id={$this->section9->id}")
            ->assertOk()->assertJsonPath('data.month', '2026-10');
    }

    public function test_a_past_month_counts_all_its_school_days_and_a_future_month_has_none(): void
    {
        $this->mark($this->rahim, '2026-09-01', 'present');

        $september = $this->sheetReport('2026-09')->assertOk();
        // 30 days minus the Fridays (4, 11, 18, 25).
        $this->assertCount(26, $september->json('data.school_days'));
        $this->assertSame('3.85', $september->json('data.students.0.percentage'));

        $this->sheetReport('2026-11')->assertOk()->assertJsonPath('data.school_days', [])->assertJsonPath('data.students.0.percentage', '0.00');
        $this->sheetReport('2027-01')->assertUnprocessable()->assertJsonValidationErrors(['month']);
    }

    public function test_a_student_who_left_mid_month_shows_only_their_days(): void
    {
        $this->mark($this->salma, '2026-10-01', 'present');
        $this->mark($this->salma, '2026-10-03', 'absent');
        $this->salma->update(['status' => StudentEnrolment::STATUS_LEFT]);
        // A leaver with nothing recorded in the month is not on the report at all.
        $this->enrol($this->section9, 'science', null, 8, ['name_en' => 'Gone'])->update(['status' => StudentEnrolment::STATUS_LEFT]);

        $students = $this->sheetReport()->assertOk()->json('data.students');

        $this->assertSame(['Rahim', 'Karim', 'Salma'], array_column(array_column($students, 'student'), 'name_en'));
        $this->assertSame(['2026-10-01', '2026-10-03'], array_keys($students[2]['days']));
        // Over the two days recorded, not the seven school days.
        $this->assertSame('50.00', $students[2]['percentage']);
    }

    public function test_a_holiday_added_after_attendance_was_marked_hides_that_day(): void
    {
        $this->mark($this->rahim, '2026-10-06', 'absent');
        $this->mark($this->rahim, '2026-10-07', 'present');

        Holiday::factory()->create(['date' => '2026-10-06', 'academic_year_id' => $this->year->id]);

        $data = $this->sheetReport()->assertOk()->json('data');

        $this->assertNotContains('2026-10-06', $data['school_days']);
        $this->assertCount(6, $data['school_days']);
        $this->assertSame(['2026-10-07'], array_keys($data['students'][0]['days']));
        $this->assertSame(0, $data['students'][0]['totals']['absent']);
        $this->assertSame('16.67', $data['students'][0]['percentage']);
    }

    public function test_the_student_endpoint_returns_the_month_and_the_year_to_date(): void
    {
        // Three Sundays in January attended, a fourth missed.
        foreach (['2026-01-04', '2026-01-11', '2026-01-18'] as $date) {
            $this->mark($this->rahim, $date, 'present');
        }
        $this->mark($this->rahim, '2026-01-25', 'absent');
        $this->mark($this->rahim, '2026-10-01', 'late');
        $this->mark($this->rahim, '2026-10-03', 'present');

        $response = $this->as($this->classTeacher)->getJson("/api/attendance/students/{$this->rahim->student_id}?month=2026-10")
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'month', 'student' => ['id', 'student_code', 'name_en', 'name_bn', 'roll_number'], 'school_days', 'days',
                'totals' => ['present', 'absent', 'late', 'leave'], 'percentage',
                'year_to_date' => ['school_days', 'totals' => ['present', 'absent', 'late', 'leave'], 'percentage'],
            ]])
            ->assertJsonPath('data.student.id', $this->rahim->student_id)
            ->assertJsonPath('data.month', '2026-10')
            ->assertJsonPath('data.days', ['2026-10-01' => 'late', '2026-10-03' => 'present'])
            ->assertJsonPath('data.totals.present', 1)
            ->assertJsonPath('data.percentage', '28.57');

        // Jan 1 to Oct 8 is 281 days, 40 of them Fridays.
        $ytd = $response->json('data.year_to_date');
        $this->assertSame(241, $ytd['school_days']);
        $this->assertSame(['present' => 4, 'absent' => 1, 'late' => 1, 'leave' => 0], $ytd['totals']);
        $this->assertSame('2.07', $ytd['percentage']);

        $this->as($this->classTeacher)->getJson("/api/attendance/students/{$this->rahim->student_id}")
            ->assertOk()->assertJsonPath('data.month', '2026-10');
    }

    public function test_the_student_endpoint_is_404_for_unknown_and_unenrolled_students(): void
    {
        $this->as($this->admin)->getJson('/api/attendance/students/999999')->assertNotFound();

        $unenrolled = Student::factory()->create();
        $this->as($this->admin)->getJson("/api/attendance/students/{$unenrolled->id}")->assertNotFound();
        $this->as($this->admin)->getJson('/api/attendance/students/1abc')->assertNotFound();
    }

    // --- own records ---------------------------------------------------------------------

    /** @return array{User, User, StudentEnrolment} the student login, the guardian login and the enrolment */
    private function loginsFor(StudentEnrolment $enrolment, ?User $guardian = null): array
    {
        $login = $this->userWithRole('student');
        $guardian ??= $this->userWithRole('parent');
        $enrolment->student->update(['user_id' => $login->id, 'guardian_user_id' => $guardian->id]);

        return [$login, $guardian, $enrolment];
    }

    public function test_a_student_sees_only_their_own_month(): void
    {
        [$login] = $this->loginsFor($this->rahim);
        $this->mark($this->rahim, '2026-10-01', 'present');
        $this->mark($this->karim, '2026-10-01', 'absent');

        $this->as($login)->getJson('/api/my/attendance?month=2026-10')
            ->assertOk()
            ->assertJsonPath('data.student.id', $this->rahim->student_id)
            ->assertJsonPath('data.days', ['2026-10-01' => 'present'])
            ->assertJsonPath('data.totals.absent', 0)
            ->assertJsonPath('data.percentage', '14.29')
            ->assertJsonStructure(['data' => ['year_to_date' => ['school_days', 'totals', 'percentage']]]);

        // The month defaults to the current one; a student with no record gets 404.
        $this->as($login)->getJson('/api/my/attendance')->assertOk()->assertJsonPath('data.month', '2026-10');
        $this->as($this->userWithRole('student'))->getJson('/api/my/attendance')->assertNotFound();
        $this->as($login)->getJson('/api/my/attendance?month=2026-1')->assertUnprocessable();
    }

    public function test_a_guardian_sees_each_child_but_not_another_familys(): void
    {
        [, $guardian] = $this->loginsFor($this->rahim);
        $this->loginsFor($this->karim, $guardian);
        [, , $stranger] = $this->loginsFor($this->salma);
        $this->mark($this->rahim, '2026-10-01', 'present');
        $this->mark($this->karim, '2026-10-01', 'absent');

        $this->as($guardian)->getJson("/api/my/children/{$this->rahim->student_id}/attendance?month=2026-10")
            ->assertOk()->assertJsonPath('data.student.id', $this->rahim->student_id)->assertJsonPath('data.days', ['2026-10-01' => 'present']);
        $this->as($guardian)->getJson("/api/my/children/{$this->karim->student_id}/attendance?month=2026-10")
            ->assertOk()->assertJsonPath('data.days', ['2026-10-01' => 'absent']);

        $this->as($guardian)->getJson("/api/my/children/{$stranger->student_id}/attendance")->assertForbidden();
        $this->as($guardian)->getJson('/api/my/children/999999/attendance')->assertForbidden();
    }

    public function test_the_own_record_endpoints_are_role_scoped(): void
    {
        [$login, $guardian] = $this->loginsFor($this->rahim);

        $this->as($guardian)->getJson('/api/my/attendance')->assertForbidden();
        $this->as($login)->getJson("/api/my/children/{$this->rahim->student_id}/attendance")->assertForbidden();
        $this->as($this->classTeacher)->getJson('/api/my/attendance')->assertForbidden();
        $this->as($this->admin)->getJson("/api/my/children/{$this->rahim->student_id}/attendance")->assertForbidden();
    }

    // --- legacy --------------------------------------------------------------------------

    public function test_the_legacy_attendance_routes_are_gone(): void
    {
        $this->as($this->admin)->getJson('/api/attendances')->assertNotFound();
        $this->as($this->admin)->postJson('/api/attendances', [])->assertNotFound();
        $this->as($this->admin)->postJson('/api/attendances/bulk', [])->assertNotFound();
        $this->as($this->admin)->getJson("/api/attendances/report/{$this->rahim->student_id}")->assertNotFound();
    }

    public function test_deleting_a_section_class_or_student_with_attendance_is_refused(): void
    {
        $this->mark($this->rahim, '2026-10-01', 'present');

        $this->as($this->admin)->deleteJson("/api/students/{$this->rahim->student_id}")
            ->assertStatus(409)->assertJsonPath('message', 'Student has attendance records and cannot be deleted.');

        // The class and section also have enrolled students, which is checked first, so
        // move the student out to prove the attendance rows alone block the delete.
        $this->rahim->update([
            'section_id' => Section::factory()->create(['class_id' => $this->class10->id, 'shift_id' => $this->shift->id])->id,
            'class_id' => $this->class10->id,
        ]);
        $this->karim->delete();
        $this->salma->delete();

        $this->as($this->admin)->deleteJson("/api/sections/{$this->section9->id}")
            ->assertStatus(409)->assertJsonPath('message', 'Section has attendance records and cannot be deleted.');
        // A soft-deleted section no longer blocks the class, but its attendance rows do.
        $this->section9->delete();
        $this->as($this->admin)->deleteJson("/api/classes/{$this->class9->id}")
            ->assertStatus(409)->assertJsonPath('message', 'Class has attendance records and cannot be deleted.');
    }
}
