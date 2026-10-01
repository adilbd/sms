<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamResult;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\Subject;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PromotionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicYear $from;

    private AcademicYear $to;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@sms.com')->firstOrFail();
        $this->from = AcademicYear::factory()->create(['year' => 2026, 'is_active' => true]);
        $this->to = AcademicYear::factory()->create(['year' => 2027]);
        $this->shift = Shift::factory()->create();
    }

    private function section(int $number, string $code = 'A', array $attributes = []): Section
    {
        $class = Classes::where('number', $number)->first() ?? Classes::factory()->create(['number' => $number]);

        return Section::factory()->create([
            'class_id' => $class->id, 'shift_id' => $this->shift->id, 'code' => $code, 'name' => "Section {$code}",
        ] + $attributes);
    }

    private function enrol(Section $section, array $enrolment = [], ?AcademicYear $year = null, array $student = []): StudentEnrolment
    {
        $guardian = User::factory()->create(['is_active' => true]);
        $guardian->assignRole('parent');
        $login = User::factory()->create(['is_active' => true]);
        $login->assignRole('student');

        $created = Student::factory()->create($student + ['user_id' => $login->id, 'guardian_user_id' => $guardian->id]);

        return StudentEnrolment::factory()->create($enrolment + [
            'student_id' => $created->id,
            'academic_year_id' => ($year ?? $this->from)->id,
            'section_id' => $section->id,
            'class_id' => $section->class_id,
        ]);
    }

    private function body(Section $source, ?Section $target, array $exceptions = [], array $extra = []): array
    {
        return array_replace([
            'from_academic_year_id' => $this->from->id,
            'to_academic_year_id' => $this->to->id,
            'section_id' => $source->id,
            'default_target_section_id' => $target?->id,
            'exceptions' => $exceptions,
        ], $extra);
    }

    private function apply(array $body)
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/promotions', $body);
    }

    /** @return array{0: Section, 1: Subject} */
    private function scienceElective(Classes $class): array
    {
        $subject = Subject::factory()->create();
        ClassSubject::factory()->create([
            'class_id' => $class->id, 'subject_id' => $subject->id, 'group' => 'science', 'type' => ClassSubject::TYPE_OPTIONAL,
        ]);

        return [$class, $subject];
    }

    private function assertNothingWritten(int $enrolments, int $active): void
    {
        $this->assertSame($enrolments, StudentEnrolment::count());
        $this->assertSame($active, StudentEnrolment::where('status', 'active')->count());
        $this->assertSame(0, Student::where('status', '!=', 'active')->count());
    }

    // ---- Apply: happy paths --------------------------------------------------------------

    public function test_promotes_a_section_with_retain_leave_and_promote_elsewhere_exceptions(): void
    {
        Carbon::setTestNow('2026-12-31 20:00:00'); // 2027-01-01 in Asia/Dhaka
        $source = $this->section(6);
        $target = $this->section(7, 'A');
        $other = $this->section(7, 'B');
        $same = $this->section(6, 'A2');

        $plain = $this->enrol($source, ['roll_number' => 1]);
        $retained = $this->enrol($source, ['roll_number' => 2]);
        $leaver = $this->enrol($source, ['roll_number' => 3]);
        $elsewhere = $this->enrol($source, ['roll_number' => 4]);

        $this->apply($this->body($source, $target, [
            ['student_id' => $retained->student_id, 'action' => 'retain', 'target_section_id' => $same->id],
            ['student_id' => $leaver->student_id, 'action' => 'leave'],
            ['student_id' => $elsewhere->student_id, 'action' => 'promote', 'target_section_id' => $other->id],
        ]))
            ->assertOk()
            ->assertJsonPath('data.summary', ['promoted' => 2, 'retained' => 1, 'left' => 1, 'graduated' => 0, 'skipped' => 0])
            ->assertJsonPath('message', 'Promotion applied successfully')
            ->assertJsonStructure(['data' => ['target_sections' => [['id', 'name', 'enrolled_after']]]]);

        $new = fn (StudentEnrolment $e) => StudentEnrolment::where('student_id', $e->student_id)->where('academic_year_id', $this->to->id)->first();

        $this->assertSame($target->id, $new($plain)->section_id);
        $this->assertSame($target->class_id, $new($plain)->class_id);
        $this->assertNull($new($plain)->roll_number);
        $this->assertSame('active', $new($plain)->status);
        $this->assertSame($same->id, $new($retained)->section_id);
        $this->assertSame($same->class_id, $new($retained)->class_id);
        $this->assertSame($other->id, $new($elsewhere)->section_id);
        $this->assertNull($new($leaver));

        $this->assertSame('promoted', $plain->refresh()->status);
        $this->assertSame('retained', $retained->refresh()->status);
        $this->assertSame('left', $leaver->refresh()->status);
        $this->assertSame('promoted', $elsewhere->refresh()->status);

        $student = $leaver->student->refresh();
        $this->assertSame('left', $student->status);
        $this->assertSame('2027-01-01', $student->leaving_date->toDateString());
        $this->assertFalse(User::findOrFail($student->user_id)->is_active);
        // The guardian had only this child, so the login is deactivated.
        $this->assertFalse(User::findOrFail($student->guardian_user_id)->is_active);
        // The others' guardians stay active.
        $this->assertTrue(User::findOrFail($plain->student->guardian_user_id)->is_active);

        Carbon::setTestNow();
    }

    public function test_a_guardian_login_stays_active_while_a_sibling_remains(): void
    {
        $source = $this->section(6);
        $target = $this->section(7);
        $leaver = $this->enrol($source);
        $sibling = $this->enrol($source, [], null, ['guardian_user_id' => $leaver->student->guardian_user_id]);

        $this->apply($this->body($source, $target, [['student_id' => $leaver->student_id, 'action' => 'leave']]))->assertOk();

        $this->assertTrue(User::findOrFail($sibling->student->guardian_user_id)->is_active);
    }

    public function test_class_12_students_graduate_without_a_default_target(): void
    {
        $source = $this->section(12);
        $a = $this->enrol($source, ['group' => 'science']);
        $b = $this->enrol($source, ['group' => 'science']);

        $this->apply($this->body($source, null, [['student_id' => $b->student_id, 'action' => 'promote']]))
            ->assertOk()
            ->assertJsonPath('data.summary.graduated', 2)
            ->assertJsonPath('data.target_sections', []);

        foreach ([$a, $b] as $enrolment) {
            $this->assertSame('graduated', $enrolment->refresh()->status);
            $this->assertSame('graduated', $enrolment->student->refresh()->status);
            $this->assertNotNull($enrolment->student->leaving_date);
            $this->assertFalse(User::findOrFail($enrolment->student->user_id)->is_active);
        }
        $this->assertSame(0, StudentEnrolment::where('academic_year_id', $this->to->id)->count());
    }

    public function test_group_and_fourth_subject_carry_over_from_nine_to_ten(): void
    {
        [$class10, $subject] = $this->scienceElective(Classes::factory()->create(['number' => 10]));
        $source = $this->section(9);
        $target = Section::factory()->create(['class_id' => $class10->id, 'shift_id' => $this->shift->id, 'code' => 'A']);
        $enrolment = $this->enrol($source, ['group' => 'science', 'optional_subject_id' => $subject->id]);

        $this->apply($this->body($source, $target))->assertOk()->assertJsonPath('data.summary.promoted', 1);

        $new = StudentEnrolment::where('student_id', $enrolment->student_id)->where('academic_year_id', $this->to->id)->firstOrFail();
        $this->assertSame('science', $new->group);
        $this->assertSame($subject->id, $new->optional_subject_id);
    }

    public function test_entering_class_nine_takes_the_group_from_the_section_and_resets_the_fourth_subject(): void
    {
        [$class9, $subject] = $this->scienceElective(Classes::factory()->create(['number' => 9]));
        $source = $this->section(8);
        $target = Section::factory()->create(['class_id' => $class9->id, 'shift_id' => $this->shift->id, 'code' => 'A', 'group' => 'science']);
        $plain = $this->enrol($source);
        $chosen = $this->enrol($source);

        $this->apply($this->body($source, $target, [
            ['student_id' => $chosen->student_id, 'action' => 'promote', 'optional_subject_id' => $subject->id],
        ]))->assertOk();

        $new = fn ($e) => StudentEnrolment::where('student_id', $e->student_id)->where('academic_year_id', $this->to->id)->firstOrFail();
        $this->assertSame('science', $new($plain)->group);
        $this->assertNull($new($plain)->optional_subject_id);
        $this->assertSame($subject->id, $new($chosen)->optional_subject_id);
    }

    public function test_entering_class_nine_takes_the_group_from_the_exception_for_an_ungrouped_section(): void
    {
        $source = $this->section(8);
        $target = $this->section(9);
        $enrolment = $this->enrol($source);

        $this->apply($this->body($source, $target, [
            ['student_id' => $enrolment->student_id, 'action' => 'promote', 'group' => 'humanities'],
        ]))->assertOk();

        $this->assertSame('humanities', StudentEnrolment::where('student_id', $enrolment->student_id)->where('academic_year_id', $this->to->id)->value('group'));
    }

    public function test_an_empty_section_applies_with_zero_counts(): void
    {
        $this->apply($this->body($this->section(6), $this->section(7)))
            ->assertOk()
            ->assertJsonPath('data.summary', ['promoted' => 0, 'retained' => 0, 'left' => 0, 'graduated' => 0, 'skipped' => 0]);
    }

    // ---- Apply: validation, nothing written ------------------------------------------------

    public function test_the_target_year_must_be_later(): void
    {
        $source = $this->section(6);
        $this->enrol($source);

        $this->apply($this->body($source, $this->section(7), [], ['to_academic_year_id' => $this->from->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['to_academic_year_id']);
        $this->assertNothingWritten(1, 1);
    }

    public function test_the_section_must_belong_to_the_source_class(): void
    {
        $source = $this->section(6);
        $this->enrol($source);
        $wrongClass = Classes::factory()->create(['number' => 3]);

        $this->apply($this->body($source, $this->section(7), [], ['class_id' => $wrongClass->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id']);
        $this->assertNothingWritten(1, 1);
    }

    public function test_exceptions_must_name_distinct_students_of_the_section(): void
    {
        $source = $this->section(6);
        $target = $this->section(7);
        $mine = $this->enrol($source);
        $stranger = $this->enrol($this->section(6, 'B'));

        $this->apply($this->body($source, $target, [
            ['student_id' => $stranger->student_id, 'action' => 'leave'],
            ['student_id' => $mine->student_id, 'action' => 'leave'],
            ['student_id' => $mine->student_id, 'action' => 'retain'],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exceptions.0.student_id', 'exceptions.2.student_id'])
            ->assertJsonMissingValidationErrors(['exceptions.1.student_id']);
        $this->assertNothingWritten(2, 2);
    }

    public function test_a_student_already_enrolled_in_the_target_year_is_rejected(): void
    {
        $source = $this->section(6);
        $target = $this->section(7);
        $listed = $this->enrol($source);
        $unlisted = $this->enrol($source, [], null, ['name_en' => 'Unlisted Student']);
        $this->enrol($target, ['student_id' => $listed->student_id], $this->to);
        $this->enrol($target, ['student_id' => $unlisted->student_id], $this->to);

        $this->apply($this->body($source, $target, [['student_id' => $listed->student_id, 'action' => 'promote']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exceptions.0.student_id', 'already_enrolled'])
            ->assertJsonPath('errors.already_enrolled.0', 'Already enrolled in the target academic year: Unlisted Student.');
        $this->assertSame(2, StudentEnrolment::where('academic_year_id', $this->from->id)->where('status', 'active')->count());
    }

    public function test_the_target_section_must_be_in_the_right_class(): void
    {
        $source = $this->section(6);
        $enrolment = $this->enrol($source);
        $this->enrol($source);

        $this->apply($this->body($source, $this->section(8)))
            ->assertUnprocessable()->assertJsonValidationErrors(['default_target_section_id']);

        $this->apply($this->body($source, $this->section(7), [
            ['student_id' => $enrolment->student_id, 'action' => 'retain', 'target_section_id' => $this->section(7, 'B')->id],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['exceptions.0.target_section_id']);

        $this->assertNothingWritten(2, 2);
    }

    public function test_a_default_target_is_required_unless_nobody_is_promoted(): void
    {
        $source = $this->section(6);
        $this->enrol($source);

        $this->apply($this->body($source, null))
            ->assertUnprocessable()->assertJsonValidationErrors(['default_target_section_id']);
        $this->assertNothingWritten(1, 1);
    }

    public function test_an_inactive_target_shift_or_section_is_rejected(): void
    {
        $source = $this->section(6);
        $this->enrol($source);
        $closedShift = Shift::factory()->inactive()->create();
        $class7 = Classes::factory()->create(['number' => 7]);
        $target = Section::factory()->create(['class_id' => $class7->id, 'shift_id' => $closedShift->id]);

        $this->apply($this->body($source, $target))
            ->assertUnprocessable()->assertJsonValidationErrors(['default_target_section_id']);

        $inactive = Section::factory()->create(['class_id' => $class7->id, 'shift_id' => $this->shift->id, 'is_active' => false]);
        $this->apply($this->body($source, $inactive))->assertUnprocessable()->assertJsonValidationErrors(['default_target_section_id']);
        $this->assertNothingWritten(1, 1);
    }

    public function test_the_capacity_is_checked_for_the_whole_batch(): void
    {
        $source = $this->section(6);
        $target = $this->section(7, 'A', ['capacity' => 3]);
        $this->enrol($target, [], $this->to); // one seat already taken in the target year
        $this->enrol($source);
        $this->enrol($source);
        $this->enrol($source);

        $this->apply($this->body($source, $target))
            ->assertUnprocessable()->assertJsonValidationErrors(['default_target_section_id']);
        $this->assertSame(4, StudentEnrolment::count());
        $this->assertSame(0, StudentEnrolment::where('academic_year_id', $this->to->id)->where('section_id', $target->id)->where('status', 'promoted')->count());
        $this->assertSame(3, StudentEnrolment::where('status', 'active')->where('academic_year_id', $this->from->id)->count());

        // The same batch fits once a seat is freed.
        $target->update(['capacity' => 4]);
        $this->apply($this->body($source, $target))->assertOk()->assertJsonPath('data.target_sections.0.enrolled_after', 4);
    }

    public function test_retaining_into_a_nearly_full_source_section_names_the_section_and_writes_nothing(): void
    {
        $source = $this->section(6, 'A', ['capacity' => 2]);
        $target = $this->section(7);
        $this->enrol($source, [], $this->to, []); // one seat already taken in the source section next year
        $first = $this->enrol($source);
        $second = $this->enrol($source);

        $this->apply($this->body($source, $target, [
            ['student_id' => $first->student_id, 'action' => 'retain'],
            ['student_id' => $second->student_id, 'action' => 'retain'],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['section_capacity', 'exceptions.0.target_section_id', 'exceptions.1.target_section_id'])
            ->assertJsonPath('errors.section_capacity.0', 'Section A does not have enough free seats (1 free, 2 students).');
        $this->assertSame(3, StudentEnrolment::count());
        $this->assertSame(0, StudentEnrolment::where('status', 'retained')->count());
    }

    public function test_a_leaver_admitted_after_today_is_rejected_on_the_row_before_any_write(): void
    {
        $source = $this->section(6);
        $target = $this->section(7);
        $ok = $this->enrol($source);
        $future = $this->enrol($source, [], null, ['admission_date' => Carbon::now('Asia/Dhaka')->addDays(5)->toDateString()]);

        $this->apply($this->body($source, $target, [['student_id' => $future->student_id, 'action' => 'leave']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exceptions.0.action'])
            ->assertJsonMissingValidationErrors(['leaving_date']);
        $this->assertNothingWritten(2, 2);
        $this->assertSame('active', $ok->refresh()->status);
    }

    public function test_an_unlisted_class_12_graduate_admitted_after_today_is_reported_by_name(): void
    {
        $source = $this->section(12);
        $this->enrol($source, [], null, ['name_en' => 'Future Student', 'admission_date' => Carbon::now('Asia/Dhaka')->addDays(5)->toDateString()]);

        $this->apply($this->body($source, null))
            ->assertUnprocessable()
            ->assertJsonPath('errors.graduates.0', 'Future Student: The leaving date must be on or after the admission date.');
        $this->assertNothingWritten(1, 1);
    }

    public function test_a_leaver_already_enrolled_in_the_target_year_is_rejected(): void
    {
        $source = $this->section(6);
        $target = $this->section(7);
        $leaver = $this->enrol($source);
        $this->enrol($target, ['student_id' => $leaver->student_id], $this->to);

        $this->apply($this->body($source, $target, [['student_id' => $leaver->student_id, 'action' => 'leave']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exceptions.0.student_id']);
        $this->assertSame('active', $leaver->student->refresh()->status);
    }

    public function test_entering_class_nine_without_a_group_lists_the_missing_groups(): void
    {
        $source = $this->section(8);
        $target = $this->section(9);
        $listed = $this->enrol($source);
        $this->enrol($source, [], null, ['name_en' => 'Ungrouped Student']);

        $this->apply($this->body($source, $target, [['student_id' => $listed->student_id, 'action' => 'promote']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['exceptions.0.group', 'missing_groups'])
            ->assertJsonPath('errors.missing_groups.0', 'Choose a group for: Ungrouped Student.');
        $this->assertNothingWritten(2, 2);
    }

    public function test_an_invalid_fourth_subject_in_the_target_is_rejected_unless_cleared(): void
    {
        $source = $this->section(9);
        $target = $this->section(10);
        $stale = Subject::factory()->create(); // not an optional subject of Class 10
        $enrolment = $this->enrol($source, ['group' => 'science', 'optional_subject_id' => $stale->id]);

        $this->apply($this->body($source, $target, [['student_id' => $enrolment->student_id, 'action' => 'promote']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['exceptions.0.optional_subject_id']);
        $this->apply($this->body($source, $target))
            ->assertUnprocessable()->assertJsonValidationErrors(['optional_subject_id']);
        $this->assertNothingWritten(1, 1);

        $this->apply($this->body($source, $target, [['student_id' => $enrolment->student_id, 'action' => 'promote', 'optional_subject_id' => null]]))
            ->assertOk();
        $this->assertNull(StudentEnrolment::where('student_id', $enrolment->student_id)->where('academic_year_id', $this->to->id)->value('optional_subject_id'));
    }

    public function test_a_group_below_class_nine_is_rejected(): void
    {
        $source = $this->section(6);
        $target = $this->section(7);
        $enrolment = $this->enrol($source);

        $this->apply($this->body($source, $target, [['student_id' => $enrolment->student_id, 'action' => 'promote', 'group' => 'science']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['exceptions.0.group']);
        $this->assertNothingWritten(1, 1);
    }

    public function test_one_bad_row_writes_nothing_for_the_good_rows(): void
    {
        $source = $this->section(6);
        $target = $this->section(7);
        $good = $this->enrol($source);
        $bad = $this->enrol($source);

        $this->apply($this->body($source, $target, [
            ['student_id' => $bad->student_id, 'action' => 'retain', 'target_section_id' => $this->section(8)->id],
        ]))->assertUnprocessable();

        $this->assertNothingWritten(2, 2);
        $this->assertSame('active', $good->refresh()->status);
    }

    public function test_malformed_ids_are_unprocessable(): void
    {
        $source = $this->section(6);

        $this->apply(['from_academic_year_id' => 'abc', 'to_academic_year_id' => $this->to->id, 'section_id' => '1x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['from_academic_year_id', 'section_id']);
        $this->apply($this->body($source, null, [['student_id' => 'x', 'action' => 'dance']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['exceptions.0.student_id', 'exceptions.0.action']);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/promotions/preview?from_academic_year_id=x&section_id=y')
            ->assertUnprocessable()->assertJsonValidationErrors(['from_academic_year_id', 'section_id']);
    }

    // ---- Preview --------------------------------------------------------------------------

    private function annualExam(string $status = Exam::STATUS_PUBLISHED, string $type = Exam::TYPE_ANNUAL, ?string $publishedAt = '2026-12-01 00:00:00'): Exam
    {
        return Exam::factory()->create([
            'academic_year_id' => $this->from->id, 'type' => $type, 'status' => $status, 'published_at' => $publishedAt, 'name_en' => 'Annual Exam',
        ]);
    }

    private function makeResult(Exam $exam, StudentEnrolment $enrolment, bool $pass, string $gpa = '4.00', string $grade = 'A'): ExamResult
    {
        return ExamResult::factory()->create([
            'exam_id' => $exam->id, 'student_id' => $enrolment->student_id, 'enrolment_id' => $enrolment->id,
            'class_id' => $enrolment->class_id, 'section_id' => $enrolment->section_id,
            'is_pass' => $pass, 'gpa' => $pass ? $gpa : '0.00', 'grade' => $pass ? $grade : 'F',
        ]);
    }

    private function preview(Section $section, array $query = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->getJson('/api/promotions/preview?'.http_build_query([
            'from_academic_year_id' => $this->from->id, 'section_id' => $section->id,
        ] + $query));
    }

    public function test_preview_lists_active_students_by_roll_with_suggestions(): void
    {
        $source = $this->section(6);
        $next = $this->section(7);
        $this->section(7, 'B');
        $passed = $this->enrol($source, ['roll_number' => 2]);
        $failed = $this->enrol($source, ['roll_number' => 1]);
        $noResult = $this->enrol($source, ['roll_number' => 3]);
        $this->enrol($source, ['roll_number' => 4, 'status' => 'left']);
        $this->enrol($this->section(6, 'Z'));

        $exam = $this->annualExam();
        $this->makeResult($exam, $passed, true, '4.50', 'A');
        $this->makeResult($exam, $failed, false);

        $response = $this->preview($source)->assertOk();

        $response->assertJsonPath('data.class.number', 6)
            ->assertJsonPath('data.next_class.number', 7)
            ->assertJsonPath('data.suggested_target_section_id', $next->id)
            ->assertJsonPath('data.needs_group_choice', false)
            ->assertJsonCount(3, 'data.rows')
            ->assertJsonPath('data.rows.0.student.id', $failed->student_id)
            ->assertJsonPath('data.rows.0.suggested_action', 'retain')
            ->assertJsonPath('data.rows.0.exam_result.is_pass', false)
            ->assertJsonPath('data.rows.1.suggested_action', 'promote')
            ->assertJsonPath('data.rows.1.exam_result.exam_name', 'Annual Exam')
            ->assertJsonPath('data.rows.1.exam_result.gpa', '4.50')
            ->assertJsonPath('data.rows.1.exam_result.grade', 'A')
            ->assertJsonPath('data.rows.2.student.id', $noResult->student_id)
            ->assertJsonPath('data.rows.2.exam_result', null)
            ->assertJsonPath('data.rows.2.suggested_action', 'promote');
        $this->assertArrayHasKey('already_enrolled_in_target', $response->json('data.rows.0'));
        $this->assertNull($response->json('data.rows.0.already_enrolled_in_target'));
    }

    public function test_preview_ignores_unpublished_and_non_annual_exams(): void
    {
        $source = $this->section(6);
        $failed = $this->enrol($source);
        $this->makeResult($this->annualExam(Exam::STATUS_PROCESSED, Exam::TYPE_ANNUAL, null), $failed, false);
        $this->makeResult($this->annualExam(Exam::STATUS_PUBLISHED, Exam::TYPE_HALF_YEARLY), $failed, false);

        $this->preview($source)->assertOk()
            ->assertJsonPath('data.rows.0.exam_result', null)
            ->assertJsonPath('data.rows.0.suggested_action', 'promote');
    }

    public function test_preview_uses_the_latest_published_annual_exam(): void
    {
        $source = $this->section(6);
        $enrolment = $this->enrol($source);
        $this->makeResult($this->annualExam(Exam::STATUS_PUBLISHED, Exam::TYPE_ANNUAL, '2026-11-01 00:00:00'), $enrolment, false);
        $this->makeResult($this->annualExam(Exam::STATUS_PUBLISHED, Exam::TYPE_ANNUAL, '2026-12-01 00:00:00'), $enrolment, true);

        $this->preview($source)->assertOk()->assertJsonPath('data.rows.0.suggested_action', 'promote');
    }

    public function test_preview_for_class_12_suggests_graduate_and_has_no_next_class(): void
    {
        $source = $this->section(12);
        $failed = $this->enrol($source, ['group' => 'science']);
        $this->makeResult($this->annualExam(), $failed, false);

        $this->preview($source)->assertOk()
            ->assertJsonPath('data.next_class', null)
            ->assertJsonPath('data.suggested_target_section_id', null)
            ->assertJsonPath('data.rows.0.suggested_action', 'graduate');
    }

    public function test_preview_flags_group_choice_and_matches_the_section_by_code_and_shift(): void
    {
        $source = $this->section(8, 'A');
        $otherShift = Shift::factory()->create();
        $class9 = Classes::factory()->create(['number' => 9]);
        Section::factory()->create(['class_id' => $class9->id, 'shift_id' => $otherShift->id, 'code' => 'A']);
        Section::factory()->create(['class_id' => $class9->id, 'shift_id' => $this->shift->id, 'code' => 'B']);

        $this->preview($source)->assertOk()
            ->assertJsonPath('data.needs_group_choice', true)
            ->assertJsonPath('data.suggested_target_section_id', null);

        $match = Section::factory()->create(['class_id' => $class9->id, 'shift_id' => $this->shift->id, 'code' => 'A']);
        $this->preview($source)->assertJsonPath('data.suggested_target_section_id', $match->id);
        $this->preview($this->section(9, 'C'))->assertJsonPath('data.needs_group_choice', false);
    }

    public function test_preview_flags_students_already_enrolled_in_the_target_year(): void
    {
        $source = $this->section(6);
        $done = $this->enrol($source, ['roll_number' => 1]);
        $this->enrol($source, ['roll_number' => 2]);
        $this->enrol($this->section(7), ['student_id' => $done->student_id], $this->to);

        $this->preview($source, ['to_academic_year_id' => $this->to->id])->assertOk()
            ->assertJsonPath('data.rows.0.already_enrolled_in_target', true)
            ->assertJsonPath('data.rows.0.suggested_action', 'skip')
            ->assertJsonPath('data.rows.1.already_enrolled_in_target', false)
            ->assertJsonPath('data.rows.1.suggested_action', 'promote');
    }

    public function test_preview_of_an_empty_section_is_empty(): void
    {
        $this->preview($this->section(6))->assertOk()->assertJsonPath('data.rows', []);
    }

    public function test_preview_rejects_a_section_outside_the_given_class(): void
    {
        $other = Classes::factory()->create(['number' => 3]);

        $this->preview($this->section(6), ['class_id' => $other->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id']);
    }

    // ---- Skip, enrolment start and the result screen ---------------------------------------

    public function test_a_skipped_student_is_left_untouched(): void
    {
        $source = $this->section(6);
        $target = $this->section(7);
        $skipped = $this->enrol($source, ['roll_number' => 1]);
        $promoted = $this->enrol($source, ['roll_number' => 2]);

        $this->apply($this->body($source, $target, [['student_id' => $skipped->student_id, 'action' => 'skip']]))
            ->assertOk()
            ->assertJsonPath('data.summary', ['promoted' => 1, 'retained' => 0, 'left' => 0, 'graduated' => 0, 'skipped' => 1])
            ->assertJsonPath('data.target_sections.0.enrolled_after', 1);

        $this->assertSame('active', $skipped->refresh()->status);
        $this->assertSame('active', $skipped->student->refresh()->status);
        $this->assertSame(0, StudentEnrolment::where('student_id', $skipped->student_id)->where('academic_year_id', $this->to->id)->count());
        $this->assertSame('promoted', $promoted->refresh()->status);
    }

    public function test_a_skipped_student_takes_no_seat(): void
    {
        $source = $this->section(6);
        $target = $this->section(7, 'A', ['capacity' => 1]);
        $skipped = $this->enrol($source);
        $this->enrol($source);

        $this->apply($this->body($source, $target, [['student_id' => $skipped->student_id, 'action' => 'skip']]))
            ->assertOk()->assertJsonPath('data.summary.promoted', 1);
    }

    public function test_an_already_enrolled_student_can_be_skipped_while_the_rest_is_promoted(): void
    {
        $source = $this->section(6);
        $target = $this->section(7);
        $done = $this->enrol($source);
        $rest = $this->enrol($source);
        $existing = $this->enrol($target, ['student_id' => $done->student_id], $this->to);

        $this->apply($this->body($source, $target, [['student_id' => $done->student_id, 'action' => 'skip']]))
            ->assertOk()
            ->assertJsonPath('data.summary.promoted', 1)
            ->assertJsonPath('data.summary.skipped', 1);

        $this->assertSame('active', $done->refresh()->status);
        $this->assertSame(1, StudentEnrolment::where('student_id', $done->student_id)->where('academic_year_id', $this->to->id)->count());
        $this->assertSame($existing->section_id, $existing->refresh()->section_id);
        $this->assertSame('promoted', $rest->refresh()->status);
    }

    public function test_skip_is_validated_like_the_other_actions(): void
    {
        $source = $this->section(6);
        $stranger = $this->enrol($this->section(6, 'B'));
        $target = $this->section(7);

        $this->apply($this->body($source, $target, [['student_id' => $stranger->student_id, 'action' => 'skip']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['exceptions.0.student_id']);
        $this->apply($this->body($source, $target, [['student_id' => $stranger->student_id, 'action' => 'jump']]))
            ->assertUnprocessable()->assertJsonValidationErrors(['exceptions.0.action']);
    }

    public function test_new_enrolments_start_with_the_target_years_start_date(): void
    {
        $source = $this->section(6);
        $target = $this->section(7);
        $a = $this->enrol($source);
        $b = $this->enrol($source);

        $this->apply($this->body($source, $target, [['student_id' => $b->student_id, 'action' => 'retain']]))->assertOk();

        foreach ([$a, $b] as $enrolment) {
            $new = StudentEnrolment::where('student_id', $enrolment->student_id)->where('academic_year_id', $this->to->id)->firstOrFail();
            $this->assertSame('2027-01-01', $new->enrolled_on->toDateString());
        }
    }

    public function test_the_result_lists_target_sections_with_their_class_and_shift(): void
    {
        $source = $this->section(6);
        $target = $this->section(7, 'A');
        $this->enrol($source);

        $this->apply($this->body($source, $target))
            ->assertOk()
            ->assertJsonPath('data.target_sections.0.name', 'Section A')
            ->assertJsonPath('data.target_sections.0.class_name', $target->class->name)
            ->assertJsonPath('data.target_sections.0.shift_name', $this->shift->name_en);
    }

    // ---- Authorization --------------------------------------------------------------------

    public function test_guests_get_401(): void
    {
        $this->getJson('/api/promotions/preview')->assertUnauthorized();
        $this->postJson('/api/promotions', [])->assertUnauthorized();
    }

    public function test_only_users_who_can_edit_students_may_promote(): void
    {
        $source = $this->section(6);
        $target = $this->section(7);
        $this->enrol($source);

        foreach (['teacher', 'student', 'parent'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user, 'sanctum')->getJson('/api/promotions/preview?'.http_build_query([
                'from_academic_year_id' => $this->from->id, 'section_id' => $source->id,
            ]))->assertForbidden();
            $this->actingAs($user, 'sanctum')->postJson('/api/promotions', $this->body($source, $target))->assertForbidden();
        }

        $this->assertNothingWritten(1, 1);
    }
}
