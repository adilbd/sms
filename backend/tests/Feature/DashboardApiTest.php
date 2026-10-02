<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Classes;
use App\Models\ClassSection;
use App\Models\Exam;
use App\Models\ExamMark;
use App\Models\ExamResult;
use App\Models\ExamSubject;
use App\Models\Holiday;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\SubjectAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsFees;
use Tests\TestCase;

/**
 * GET /api/dashboard: real, role-appropriate numbers (admin, teacher, office) for the
 * active year and today in Asia/Dhaka. BuildsFees freezes "now" at Thursday 2026-10-15.
 */
class DashboardApiTest extends TestCase
{
    use BuildsFees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFees();
    }

    private function dashboard(User $user)
    {
        return $this->as($user)->getJson('/api/dashboard');
    }

    private function mark(StudentEnrolment $enrolment, string $status, string $date = '2026-10-15'): Attendance
    {
        return Attendance::factory()->create(['enrolment_id' => $enrolment->id, 'date' => $date, 'status' => $status]);
    }

    private function openExam(?array $classes = null, array $extra = []): Exam
    {
        $exam = $this->createExam($classes, $extra);
        $exam->update(['status' => Exam::STATUS_MARKS_ENTRY]);

        return $exam;
    }

    private function examSubject(Exam $exam, Classes $class, $subject): ExamSubject
    {
        return ExamSubject::where('exam_id', $exam->id)->where('class_id', $class->id)->where('subject_id', $subject->id)->firstOrFail();
    }

    private function examResult(Exam $exam, StudentEnrolment $enrolment, bool $pass, string $gpa): ExamResult
    {
        return ExamResult::factory()->create([
            'exam_id' => $exam->id, 'student_id' => $enrolment->student_id, 'enrolment_id' => $enrolment->id,
            'class_id' => $enrolment->class_id, 'section_id' => $enrolment->section_id, 'is_pass' => $pass, 'gpa' => $gpa,
        ]);
    }

    private function teacherOf(Section $section, \App\Models\Subject $subject): User
    {
        return $this->assignedTeacher($section, $subject);
    }

    // --- admin ---------------------------------------------------------------------------

    public function test_the_admin_counts_match_the_enrolments(): void
    {
        $evening = Shift::factory()->create();
        $section6 = Section::factory()->create(['class_id' => Classes::factory()->create(['number' => 6])->id, 'shift_id' => $evening->id]);
        Section::factory()->create(['class_id' => $this->class10->id, 'shift_id' => $this->shift->id, 'is_active' => false]);

        $this->enrol($this->section9, 'science', null, 1);
        $this->enrol($this->section9, 'science', null, 2);
        $this->enrol($this->section9, 'business_studies', null, 3);
        $this->enrolStudents(3);
        $this->enrol($section6, null, null, 1);
        // Left, and promoted-away enrolments never count.
        StudentEnrolment::where('section_id', $this->section10->id)->where('roll_number', 3)->update(['status' => 'left']);

        Staff::factory()->create(['user_id' => User::factory()->create()->id]);
        Staff::factory()->create();
        Staff::factory()->create(['category' => Staff::CATEGORY_STAFF, 'position' => Staff::POSITION_STAFF]);
        Staff::factory()->former()->create();

        $data = $this->dashboard($this->admin)->assertOk()->json('data');

        $this->assertSame('admin', $data['role']);
        $this->assertSame('2026-10-15', $data['today']);
        $this->assertSame($this->year->id, $data['academic_year']['id']);
        $this->assertSame(
            ['total' => 6, 'by_level' => ['primary' => 0, 'junior_secondary' => 1, 'secondary' => 5, 'higher_secondary' => 0], 'by_group' => ['science' => 2, 'business_studies' => 1, 'humanities' => 0]],
            collect($data['counts']['students'])->except('by_shift')->all(),
        );

        $byShift = collect($data['counts']['students']['by_shift'])->mapWithKeys(fn ($row) => [$row['shift']['id'] => $row['students']]);
        $this->assertSame(5, $byShift[$this->shift->id]);
        $this->assertSame(1, $byShift[$evening->id]);

        $this->assertSame(['active' => 3, 'teachers' => 2, 'with_login' => 1], $data['counts']['staff']);
        // Section 9, 10, 6 are active; the extra Class 10 section is not.
        $this->assertSame(3, $data['counts']['sections']);
    }

    public function test_attendance_today_percentage_and_unmarked_sections(): void
    {
        foreach ([1, 2, 3, 4] as $roll) {
            $this->mark($this->enrol($this->section9, 'science', null, $roll), 'present');
        }
        $this->mark($this->enrol($this->section9, 'science', null, 5), 'absent');
        $this->enrolStudents(2);
        // An empty section has nothing to mark, so it isn't listed.
        Section::factory()->create(['class_id' => $this->class9->id, 'shift_id' => $this->shift->id]);

        $attendance = $this->dashboard($this->admin)->assertOk()->json('data.attendance_today');

        $this->assertTrue($attendance['is_school_day']);
        $this->assertNull($attendance['holiday']);
        $this->assertSame(1, $attendance['sections_marked']);
        $this->assertSame(1, $attendance['sections_not_marked']);
        $this->assertSame('80.00', $attendance['percentage']);
        $this->assertCount(2, $attendance['sections']);

        $marked = collect($attendance['sections'])->firstWhere('section.id', $this->section9->id);
        $this->assertSame(
            ['students' => 5, 'marked' => true, 'present' => 4, 'absent' => 1, 'late' => 0, 'leave' => 0, 'percentage' => '80.00'],
            collect($marked)->except('section')->all(),
        );
        $unmarked = collect($attendance['sections'])->firstWhere('section.id', $this->section10->id);
        $this->assertFalse($unmarked['marked']);
        $this->assertNull($unmarked['percentage']);
        $this->assertSame([$this->section10->id], array_column($attendance['not_marked'], 'id'));
    }

    public function test_late_students_count_as_attended(): void
    {
        $this->mark($this->enrol($this->section9, 'science', null, 1), 'present');
        $this->mark($this->enrol($this->section9, 'science', null, 2), 'late');
        $this->mark($this->enrol($this->section9, 'science', null, 3), 'leave');
        $this->mark($this->enrol($this->section9, 'science', null, 4), 'absent');

        $row = $this->dashboard($this->admin)->json('data.attendance_today.sections.0');

        $this->assertSame('50.00', $row['percentage']);
        $this->assertSame([1, 1, 1, 1], [$row['present'], $row['late'], $row['leave'], $row['absent']]);
    }

    public function test_a_friday_is_not_a_school_day(): void
    {
        $this->enrolStudents(2);
        // 2026-10-16 is a Friday (the default weekly holiday).
        $this->travelTo('2026-10-16 06:00:00');

        $attendance = $this->dashboard($this->admin)->assertOk()->json('data.attendance_today');

        $this->assertFalse($attendance['is_school_day']);
        $this->assertSame(['type' => 'weekly', 'name_en' => 'Friday', 'name_bn' => null], $attendance['holiday']);
        $this->assertSame(0, $attendance['sections_not_marked']);
        $this->assertSame([], $attendance['not_marked']);
    }

    public function test_a_listed_holiday_is_not_a_school_day_and_names_the_reason(): void
    {
        $this->enrolStudents(2);
        Holiday::factory()->create(['date' => '2026-10-15', 'name_en' => 'Durga Puja', 'name_bn' => 'দুর্গাপূজা', 'academic_year_id' => $this->year->id]);

        $attendance = $this->dashboard($this->admin)->json('data.attendance_today');

        $this->assertFalse($attendance['is_school_day']);
        $this->assertSame(['type' => 'holiday', 'name_en' => 'Durga Puja', 'name_bn' => 'দুর্গাপূজা'], $attendance['holiday']);
        $this->assertSame([], $attendance['not_marked']);
    }

    public function test_the_latest_exam_shows_pass_rate_and_top_gpa_per_class(): void
    {
        $exam = $this->createExam();
        $exam->update(['status' => Exam::STATUS_PUBLISHED]);

        $a = $this->enrol($this->section9, 'science', null, 1);
        $b = $this->enrol($this->section9, 'science', null, 2);
        [$c, $d, $e] = $this->enrolStudents(3);
        $this->examResult($exam, $a, true, '5.00');
        $this->examResult($exam, $b, false, '0.00');
        $this->examResult($exam, $c, true, '3.50');
        $this->examResult($exam, $d, true, '4.00');
        $this->examResult($exam, $e, false, '1.00');

        $exams = $this->dashboard($this->admin)->assertOk()->json('data.exams');

        $this->assertSame(['id' => $exam->id, 'name' => 'Half Yearly Exam 2026', 'status' => 'published'], $exams['latest']);
        $this->assertCount(2, $exams['classes']);
        $this->assertSame(
            ['class' => ['id' => $this->class9->id, 'number' => 9, 'name' => 'Class 9'], 'students' => 2, 'passed' => 1, 'pass_rate' => '50.00', 'top_gpa' => '5.00'],
            $exams['classes'][0],
        );
        $this->assertSame(['students' => 3, 'passed' => 2, 'pass_rate' => '66.67', 'top_gpa' => '4.00'], collect($exams['classes'][1])->except('class')->all());
    }

    public function test_a_draft_exam_is_not_the_latest_and_no_exam_means_empty_blocks(): void
    {
        $this->createExam();

        $exams = $this->dashboard($this->admin)->json('data.exams');

        $this->assertNull($exams['latest']);
        $this->assertSame([], $exams['classes']);
        $this->assertSame(['incomplete_sheets' => 0, 'total_sheets' => 0, 'exams' => []], $exams['marks_entry']);
    }

    public function test_incomplete_mark_sheets_of_exams_in_marks_entry(): void
    {
        $exam = $this->openExam([$this->class9]);
        $science1 = $this->enrol($this->section9, 'science', $this->higherMath, 1);
        $this->enrol($this->section9, 'science', null, 2);
        $this->enrol($this->section9, 'business_studies', null, 3);

        // Physics (science only, 2 students) is fully entered; Bangla (all 3) has one mark.
        foreach ([$science1, StudentEnrolment::where('roll_number', 2)->first()] as $enrolment) {
            ExamMark::factory()->create(['exam_subject_id' => $this->examSubject($exam, $this->class9, $this->physics)->id, 'student_id' => $enrolment->student_id, 'enrolment_id' => $enrolment->id]);
        }
        ExamMark::factory()->create(['exam_subject_id' => $this->examSubject($exam, $this->class9, $this->bangla)->id, 'student_id' => $science1->student_id, 'enrolment_id' => $science1->id]);

        $marks = $this->dashboard($this->admin)->assertOk()->json('data.exams.marks_entry');

        // Bangla, Physics, Accounting (1 student) and Higher Math (the 4th subject of one).
        $this->assertSame(4, $marks['total_sheets']);
        $this->assertSame(3, $marks['incomplete_sheets']);
        $this->assertSame([['exam' => ['id' => $exam->id, 'name' => 'Half Yearly Exam 2026'], 'sheets' => 4, 'incomplete' => 3]], $marks['exams']);
    }

    public function test_todays_collection_uses_the_dhaka_day_and_skips_cancelled_payments(): void
    {
        [$a, $b, $c] = $this->enrolStudents(3);
        $this->generateDues()->assertOk();
        // 17:30 UTC on the 14th is 23:30 in Dhaka: yesterday, so not in today's collection.
        $this->travelTo('2026-10-14 17:30:00');
        $this->pay($c, '25.00')->assertCreated();
        // 18:30 UTC on the 14th is 00:30 on the 15th in Dhaka: today.
        $this->travelTo('2026-10-14 18:30:00');
        $this->pay($c, '100.00')->assertCreated();
        $this->travelTo('2026-10-15 06:00:00');
        $this->pay($a, '500.00')->assertCreated();
        $this->pay($b, '300.25', 'bkash', ['transaction_id' => 'T1'])->assertCreated();
        $cancelled = $this->pay($b, '50.00')->assertCreated()->json('data.id');
        $this->as($this->admin)->postJson("/api/fee-payments/{$cancelled}/cancel", ['reason' => 'x'])->assertOk();

        $fees = $this->dashboard($this->admin)->assertOk()->json('data.fees');

        $this->assertSame(3, $fees['today']['count']);
        $this->assertSame('900.25', $fees['today']['amount']);
        $this->assertSame(
            ['cash' => '600.00', 'bkash' => '300.25', 'nagad' => '0.00', 'rocket' => '0.00'],
            collect($fees['today']['by_method'])->pluck('amount', 'method')->all(),
        );
        // October: 3 x 800 net; 925.25 paid against it (the cancelled 50 is reversed).
        $this->assertSame(['month' => '2026-10', 'net_amount' => '2400.00', 'collected_amount' => '925.25', 'outstanding_amount' => '1474.75'], $fees['month']);
    }

    public function test_recent_payments_exams_and_admissions_are_newest_first(): void
    {
        $this->enrol($this->section9, 'science', null, 1, ['admission_date' => '2026-01-05', 'name_en' => 'Oldest']);
        $this->enrol($this->section9, 'science', null, 2, ['admission_date' => '2026-03-01', 'name_en' => 'Middle']);
        $this->enrol($this->section9, 'science', null, 3, ['admission_date' => '2026-09-01', 'name_en' => 'Newest']);
        [$a] = $this->enrolStudents(1);
        $a->student->update(['admission_date' => '2025-01-01']);
        $this->generateDues()->assertOk();
        $first = $this->pay($a, '100.00')->assertCreated()->json('data.receipt_no');
        $this->travelTo('2026-10-15 07:00:00');
        $second = $this->pay($a, '200.00')->assertCreated()->json('data.receipt_no');

        $older = $this->createExam([$this->class9]);
        $older->update(['status' => Exam::STATUS_PROCESSED]);
        $this->travelTo('2026-10-15 08:00:00');
        $newer = $this->createExam([$this->class10], ['code' => 'ANNUAL-2026', 'name_en' => 'Annual']);
        $newer->update(['status' => Exam::STATUS_PUBLISHED]);
        $this->createExam([$this->class10], ['code' => 'DRAFT']);

        $recent = $this->dashboard($this->admin)->assertOk()->json('data.recent');

        $this->assertSame([$second, $first], array_column($recent['payments'], 'receipt_no'));
        $this->assertSame('200.00', $recent['payments'][0]['amount']);
        $this->assertSame(['Newest', 'Middle', 'Oldest'], array_slice(array_column($recent['students'], 'name_en'), 0, 3));
        $this->assertSame('2026-09-01', $recent['students'][0]['admission_date']);
        $this->assertSame(['id' => $this->class9->id, 'number' => 9, 'name' => 'Class 9'], $recent['students'][0]['class']);
        $this->assertSame([$newer->id, $older->id], array_column($recent['exams'], 'id'));
    }

    // --- teacher -------------------------------------------------------------------------

    public function test_a_teacher_sees_attendance_only_of_the_sections_they_lead(): void
    {
        $teacher = $this->teacherOf($this->section9, $this->physics);
        ClassSection::create([
            'class_id' => $this->section10->class_id, 'section_id' => $this->section10->id,
            'academic_year_id' => $this->year->id, 'staff_id' => Staff::where('user_id', $teacher->id)->firstOrFail()->id,
        ]);
        $this->mark($this->enrol($this->section9, 'science', null, 1), 'present');
        [$a, $b] = $this->enrolStudents(2);
        $this->mark($a, 'present');
        $this->mark($b, 'absent');

        $data = $this->dashboard($teacher)->assertOk()->json('data');

        $this->assertSame('teacher', $data['role']);
        $this->assertArrayNotHasKey('counts', $data);
        $this->assertArrayNotHasKey('fees', $data);
        $this->assertSame([$this->section10->id], array_column(array_column($data['attendance_today']['sections'], 'section'), 'id'));
        $this->assertSame('50.00', $data['attendance_today']['percentage']);
        $this->assertSame(1, $data['attendance_today']['sections_marked']);
    }

    public function test_a_teacher_dashboard_names_the_main_class_teacher_of_each_section_they_lead(): void
    {
        $teacher = $this->teacherOf($this->section9, $this->physics);
        $teacherStaff = Staff::where('user_id', $teacher->id)->firstOrFail();
        $main = Staff::factory()->create(['name_en' => 'Main Teacher']);

        // The teacher is a co-teacher of section 10, whose main teacher is someone else.
        ClassSection::create(['class_id' => $this->section10->class_id, 'section_id' => $this->section10->id, 'academic_year_id' => $this->year->id, 'staff_id' => $teacherStaff->id, 'is_main' => false]);
        ClassSection::create(['class_id' => $this->section10->class_id, 'section_id' => $this->section10->id, 'academic_year_id' => $this->year->id, 'staff_id' => $main->id, 'is_main' => true]);
        $this->mark($this->enrol($this->section10, 'science', null, 1), 'present');

        $section = $this->dashboard($teacher)->assertOk()->json('data.attendance_today.sections.0.section');

        $this->assertSame($this->section10->id, $section['id']);
        $this->assertSame($main->id, $section['class_teacher']['id']);
        $this->assertSame('Main Teacher', $section['class_teacher']['name_en']);

        $adminSections = array_column($this->dashboard($this->admin)->assertOk()->json('data.attendance_today.sections'), 'section');
        $this->assertSame($main->id, collect($adminSections)->firstWhere('id', $this->section10->id)['class_teacher']['id']);
    }

    public function test_a_teacher_sees_only_their_own_mark_sheets_with_counts(): void
    {
        $exam = $this->openExam();
        $physicsTeacher = $this->teacherOf($this->section9, $this->physics);
        $banglaTeacher = $this->teacherOf($this->section10, $this->bangla);
        $a = $this->enrol($this->section9, 'science', null, 1);
        $this->enrol($this->section9, 'science', null, 2);
        $this->enrol($this->section9, 'business_studies', null, 3);
        [$x, $y, $z] = $this->enrolStudents(3);
        ExamMark::factory()->create(['exam_subject_id' => $this->examSubject($exam, $this->class9, $this->physics)->id, 'student_id' => $a->student_id, 'enrolment_id' => $a->id]);
        ExamMark::factory()->create(['exam_subject_id' => $this->examSubject($exam, $this->class9, $this->bangla)->id, 'student_id' => $a->student_id, 'enrolment_id' => $a->id]);
        foreach ([$x, $y] as $enrolment) {
            ExamMark::factory()->create(['exam_subject_id' => $this->examSubject($exam, $this->class10, $this->bangla)->id, 'student_id' => $enrolment->student_id, 'enrolment_id' => $enrolment->id]);
        }

        $sheets = $this->dashboard($physicsTeacher)->assertOk()->json('data.mark_sheets');
        $this->assertCount(1, $sheets);
        $this->assertSame('Physics', $sheets[0]['subject']['name']);
        $this->assertSame($this->section9->id, $sheets[0]['section']['id']);
        $this->assertSame([1, 2], [$sheets[0]['entered'], $sheets[0]['total']]);
        $this->assertSame($exam->id, $sheets[0]['exam']['id']);

        $sheets = $this->dashboard($banglaTeacher)->json('data.mark_sheets');
        $this->assertCount(1, $sheets);
        $this->assertSame([2, 3], [$sheets[0]['entered'], $sheets[0]['total']]);
        $this->assertSame($this->section10->id, $sheets[0]['section']['id']);
    }

    public function test_a_teacher_sees_the_latest_pass_rate_of_the_section_they_lead(): void
    {
        $exam = $this->createExam();
        $exam->update(['status' => Exam::STATUS_PUBLISHED]);
        $teacher = $this->teacherOf($this->section9, $this->physics);
        ClassSection::create([
            'class_id' => $this->section10->class_id, 'section_id' => $this->section10->id,
            'academic_year_id' => $this->year->id, 'staff_id' => Staff::where('user_id', $teacher->id)->firstOrFail()->id,
        ]);
        [$a, $b, $c] = $this->enrolStudents(3);
        $this->examResult($exam, $a, true, '4.00');
        $this->examResult($exam, $b, true, '3.00');
        $this->examResult($exam, $c, false, '0.00');
        // Another section's results are never included.
        $this->examResult($exam, $this->enrol($this->section9, 'science', null, 1), false, '0.00');

        $rates = $this->dashboard($teacher)->assertOk()->json('data.pass_rates');

        $this->assertSame($exam->id, $rates['exam']['id']);
        $this->assertCount(1, $rates['sections']);
        $this->assertSame(['students' => 3, 'passed' => 2, 'pass_rate' => '66.67'], collect($rates['sections'][0])->except('section')->all());
        $this->assertSame($this->section10->id, $rates['sections'][0]['section']['id']);
    }

    public function test_a_teacher_with_nothing_assigned_gets_empty_sections(): void
    {
        $this->openExam();
        $this->enrolStudents(2);
        $unlinked = $this->userWithRole('teacher');
        $idle = $this->userWithRole('teacher');
        Staff::factory()->create(['user_id' => $idle->id]);

        foreach ([$unlinked, $idle] as $teacher) {
            $data = $this->dashboard($teacher)->assertOk()->json('data');

            $this->assertSame([], $data['mark_sheets']);
            $this->assertSame([], $data['attendance_today']['sections']);
            $this->assertSame(['exam' => null, 'sections' => []], $data['pass_rates']);
        }
    }

    // --- office --------------------------------------------------------------------------

    public function test_the_office_sees_collections_only(): void
    {
        [$a, $b, $c] = $this->enrolStudents(3);
        $this->generateDues()->assertOk();
        $this->pay($a, '500.00')->assertCreated();
        $this->pay($b, '300.25', 'bkash', ['transaction_id' => 'T1'])->assertCreated();
        $this->createExam()->update(['status' => Exam::STATUS_PUBLISHED]);
        $this->mark($a, 'present');

        $data = $this->dashboard($this->office)->assertOk()->json('data');

        $this->assertSame('office', $data['role']);
        foreach (['counts', 'attendance_today', 'exams', 'mark_sheets', 'recent'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }
        $this->assertSame('800.25', $data['collection_today']['amount']);
        $this->assertSame(2, $data['collection_today']['count']);
        $this->assertSame(
            ['cash' => '500.00', 'bkash' => '300.25', 'nagad' => '0.00', 'rocket' => '0.00'],
            collect($data['collection_today']['by_method'])->pluck('amount', 'method')->all(),
        );
        $this->assertSame(['month' => '2026-10', 'net_amount' => '2400.00', 'collected_amount' => '800.25', 'outstanding_amount' => '1599.75'], $data['month']);
        $this->assertSame(3, $data['students_with_outstanding_dues']);
        $this->assertCount(2, $data['recent_payments']);
        $this->assertSame('300.25', $data['recent_payments'][0]['amount']);
        $this->assertSame($b->student->student_id, $data['recent_payments'][0]['student']['student_id']);
    }

    public function test_the_admin_fee_block_has_month_today_and_overdue_dues(): void
    {
        [$a, $b] = $this->enrolStudents(2);
        $this->generateDues(['month' => '2026-09'])->assertOk();
        $this->generateDues()->assertOk();
        $this->pay($a, '1000.00')->assertCreated();
        $this->pay($b, '400.50', 'nagad', ['transaction_id' => 'N1'])->assertCreated();

        $fees = $this->dashboard($this->admin)->assertOk()->json('data.fees');

        // Payments settle the oldest dues first: a's 1000 clears September's 800 and 200 of October.
        $this->assertSame('1600.00', $fees['month']['net_amount']);
        $this->assertSame('200.00', $fees['month']['collected_amount']);
        $this->assertSame('1400.00', $fees['month']['outstanding_amount']);
        $this->assertSame('1400.50', $fees['today']['amount']);
        $this->assertSame(2, $fees['today']['count']);
        $this->assertSame('400.50', collect($fees['today']['by_method'])->firstWhere('method', 'nagad')['amount']);
        // Dues past their date and not fully paid: a's October (600), b's September (399.50 left)
        // and b's October (800) -> 3 dues.
        $this->assertSame(3, $fees['overdue']['count']);
        $this->assertSame('1799.50', $fees['overdue']['outstanding_amount']);
    }

    // --- roles, authorization and the old routes ---------------------------------------------

    public function test_a_user_with_several_roles_gets_the_widest_view(): void
    {
        $adminTeacher = $this->userWithRole('teacher');
        $adminTeacher->assignRole('admin');
        $officeTeacher = $this->userWithRole('teacher');
        $officeTeacher->assignRole('office');

        $this->assertSame('admin', $this->dashboard($adminTeacher)->assertOk()->json('data.role'));
        $this->assertArrayHasKey('counts', $this->dashboard($adminTeacher)->json('data'));
        $this->assertSame('office', $this->dashboard($officeTeacher)->assertOk()->json('data.role'));
    }

    public function test_students_and_parents_are_forbidden_and_guests_are_unauthenticated(): void
    {
        $this->dashboard($this->userWithRole('student'))->assertForbidden();
        $this->dashboard($this->userWithRole('parent'))->assertForbidden();
        $this->dashboard(User::factory()->create())->assertForbidden();
    }

    public function test_guests_are_unauthenticated(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_the_old_stub_routes_are_gone(): void
    {
        $this->as($this->admin)->getJson('/api/dashboard/stats')->assertNotFound();
        $this->as($this->admin)->getJson('/api/dashboard/recent-activities')->assertNotFound();
    }

    public function test_without_an_active_year_only_the_header_is_returned(): void
    {
        $this->year->update(['is_active' => false]);

        $this->dashboard($this->admin)->assertOk()
            ->assertJsonPath('data.role', 'admin')
            ->assertJsonPath('data.academic_year', null)
            ->assertJsonMissingPath('data.counts');
    }

    // --- performance ---------------------------------------------------------------------

    /** Adds a Class 10 section with students, attendance, marks, dues and a teacher link. */
    private function growSchool(User $teacher, Exam $exam, Staff $staff): void
    {
        $section = Section::factory()->create(['class_id' => $this->class10->id, 'shift_id' => $this->shift->id]);
        $enrolments = $this->enrolStudents(3, $section);
        $this->generateDues()->assertOk();
        $subject = $this->examSubject($exam, $this->class10, $this->bangla);

        SubjectAssignment::factory()->create([
            'staff_id' => $staff->id, 'subject_id' => $this->bangla->id, 'section_id' => $section->id,
            'class_id' => $section->class_id, 'academic_year_id' => $this->year->id,
        ]);
        // Keyed on the staff member, so only the first section grown is theirs to lead (a section may have several class teachers).
        ClassSection::firstOrCreate(
            ['academic_year_id' => $this->year->id, 'staff_id' => $staff->id],
            ['class_id' => $section->class_id, 'section_id' => $section->id],
        );

        foreach ($enrolments as $i => $enrolment) {
            $this->mark($enrolment, $i === 0 ? 'absent' : 'present');
            ExamMark::factory()->create(['exam_subject_id' => $subject->id, 'student_id' => $enrolment->student_id, 'enrolment_id' => $enrolment->id]);
            $this->examResult($exam, $enrolment, $i !== 0, '4.00');
            $this->pay($enrolment, '10.00');
        }
    }

    public function test_the_query_count_does_not_grow_with_students_or_sections(): void
    {
        $exam = $this->openExam([$this->class10]);
        $teacher = $this->userWithRole('teacher');
        $staff = Staff::factory()->create(['user_id' => $teacher->id]);
        $staff->shifts()->attach($this->shift->id);

        $count = function (User $user): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->dashboard($user)->assertOk();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };

        $users = ['admin' => $this->admin, 'office' => $this->office, 'teacher' => $teacher];

        $this->growSchool($teacher, $exam, $staff);
        $this->growSchool($teacher, $exam, $staff);
        $exam->update(['status' => Exam::STATUS_PUBLISHED]);
        foreach ($users as $user) {
            $this->dashboard($user)->assertOk(); // warm the caches
        }
        $exam->update(['status' => Exam::STATUS_MARKS_ENTRY]);
        $before = array_map($count, $users);

        for ($i = 0; $i < 4; $i++) {
            $this->growSchool($teacher, $exam, $staff);
        }
        $after = array_map($count, $users);

        $this->assertSame($before, $after);
        $this->assertLessThan(40, max($after));
        $this->assertSame(6, Section::where('class_id', $this->class10->id)->count() - 1);
    }
}
