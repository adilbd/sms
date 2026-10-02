<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Certificate;
use App\Models\ClassSection;
use App\Models\FeeDue;
use App\Models\Section;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsFees;
use Tests\TestCase;

/**
 * The certificate register (/api/certificates) and the ID card data (/api/id-cards).
 * "Now" is 2026-10-15 12:00 in Asia/Dhaka (BuildsFees); Class 10 Section A is $section10.
 */
class CertificateApiTest extends TestCase
{
    use BuildsFees, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFees();
    }

    private function student(array $attributes = [], ?Section $section = null): Student
    {
        return $this->enrol($section ?? $this->section10, null, null, null, $attributes)->student;
    }

    private function issue(string $type, Student $student, array $extra = [], ?User $as = null): \Illuminate\Testing\TestResponse
    {
        return $this->as($as ?? $this->office)->postJson('/api/certificates', ['type' => $type, 'student_id' => $student->id, ...$extra]);
    }

    private function transfer(Student $student, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->issue('transfer', $student, ['reason' => 'Family moved to another city', ...$extra]);
    }

    /** @return array{0: Staff, 1: Staff, 2: Staff} main teacher, co-teacher, head */
    private function staffOfSection10(): array
    {
        $main = Staff::factory()->create(['name_en' => 'Main Teacher']);
        $co = Staff::factory()->create(['name_en' => 'Co Teacher']);
        $head = Staff::factory()->create(['name_en' => 'Head Sir', 'position' => Staff::POSITION_HEAD, 'category' => Staff::CATEGORY_STAFF, 'designation' => 'Headmaster']);

        foreach ([$main, $co, $head] as $member) {
            $member->shifts()->attach($this->shift->id);
        }

        foreach ([[$main, true], [$co, false]] as [$member, $isMain]) {
            ClassSection::create([
                'class_id' => $this->class10->id, 'section_id' => $this->section10->id,
                'academic_year_id' => $this->year->id, 'staff_id' => $member->id, 'is_main' => $isMain,
            ]);
        }

        return [$main, $co, $head];
    }

    // Issuing

    public function test_the_three_plain_types_get_sequential_serials_and_the_snapshot(): void
    {
        [$main, , $head] = $this->staffOfSection10();
        $student = $this->student(['name_en' => 'Rahim Uddin', 'name_bn' => 'রহিম উদ্দিন', 'father_name_en' => 'Karim Uddin']);

        $this->issue('testimonial', $student, ['gpa' => '4.5', 'exam' => 'ssc', 'board' => 'Dhaka', 'passing_year' => 2025])
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'type', 'serial_no', 'status', 'student', 'issuer', 'issued_on', 'snapshot' => ['school', 'student', 'placement', 'class_teacher', 'head_teacher', 'conduct']], 'message'])
            ->assertJsonPath('data.serial_no', 'TES-2026-0001')
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.issued_on', '2026-10-15')
            ->assertJsonPath('data.snapshot.gpa', '4.50')
            ->assertJsonPath('data.snapshot.conduct', 'ভালো')
            ->assertJsonPath('data.snapshot.student.father_name_en', 'Karim Uddin')
            ->assertJsonPath('data.snapshot.placement.class_name_en', 'Class 10')
            ->assertJsonPath('data.snapshot.class_teacher.name_en', $main->name_en)
            ->assertJsonPath('data.snapshot.head_teacher.name_en', $head->name_en)
            ->assertJsonPath('data.snapshot.head_teacher.designation', 'Headmaster');

        $this->issue('study', $student)->assertCreated()->assertJsonPath('data.serial_no', 'STU-2026-0001');
        $this->issue('character', $student, ['remarks' => 'Well behaved'])->assertCreated()->assertJsonPath('data.serial_no', 'CHR-2026-0001');
        $this->issue('testimonial', $student)->assertCreated()->assertJsonPath('data.serial_no', 'TES-2026-0002');
    }

    public function test_the_snapshot_is_not_changed_by_later_edits(): void
    {
        [$main, $co] = $this->staffOfSection10();
        $student = $this->student(['name_en' => 'Old Name']);
        $id = $this->issue('character', $student)->assertCreated()->json('data.id');

        $student->update(['name_en' => 'New Name']);
        ClassSection::where('staff_id', $main->id)->update(['is_main' => false]);
        ClassSection::where('staff_id', $co->id)->update(['is_main' => true]);

        $this->as($this->office)->getJson("/api/certificates/{$id}")
            ->assertOk()
            ->assertJsonPath('data.snapshot.student.name_en', 'Old Name')
            ->assertJsonPath('data.snapshot.class_teacher.name_en', 'Main Teacher')
            ->assertJsonPath('data.student.name_en', 'New Name');
    }

    public function test_the_counter_row_is_created_and_numbers_are_never_reused(): void
    {
        $student = $this->student();
        $this->assertDatabaseCount('certificate_counters', 0);

        $first = $this->issue('character', $student)->assertCreated()->json('data.id');
        $this->assertDatabaseHas('certificate_counters', ['type' => 'character', 'year' => 2026, 'last_number' => 1]);

        $this->as($this->admin)->postJson("/api/certificates/{$first}/cancel", ['reason' => 'Typo'])->assertOk();
        $this->issue('character', $student)->assertCreated()->assertJsonPath('data.serial_no', 'CHR-2026-0002');
    }

    public function test_a_refused_issue_does_not_use_a_number(): void
    {
        $student = $this->student();
        $this->generateDues();

        $this->transfer($student)->assertStatus(409);
        $this->assertDatabaseMissing('certificate_counters', ['type' => 'transfer', 'last_number' => 1]);

        $this->transfer($student, ['allow_outstanding' => true, 'outstanding_note' => 'Guardian will pay next week'])
            ->assertCreated()->assertJsonPath('data.serial_no', 'TC-2026-0001');
    }

    public function test_a_new_dhaka_year_starts_again_at_one(): void
    {
        $student = $this->student();
        $this->issue('character', $student)->assertCreated()->assertJsonPath('data.serial_no', 'CHR-2026-0001');

        // 2026-12-31 20:00 UTC is already 1 January 2027 in Dhaka.
        $this->travelTo('2026-12-31 20:00:00');
        $this->issue('character', $student)->assertCreated()
            ->assertJsonPath('data.serial_no', 'CHR-2027-0001')
            ->assertJsonPath('data.issued_on', '2027-01-01');
    }

    // Transfer certificate

    public function test_a_transfer_is_blocked_by_outstanding_fees_and_names_the_amount(): void
    {
        $student = $this->student();
        $this->generateDues();

        $this->transfer($student)->assertStatus(409)->assertJsonPath('message', fn (string $m) => str_contains($m, '800.00'));

        $this->transfer($student, ['allow_outstanding' => true])
            ->assertUnprocessable()->assertJsonValidationErrors(['outstanding_note']);

        $this->transfer($student, ['allow_outstanding' => true, 'outstanding_note' => 'Principal approved'])
            ->assertCreated()
            ->assertJsonPath('data.snapshot.dues.status', 'overridden')
            ->assertJsonPath('data.snapshot.dues.outstanding', '800.00')
            ->assertJsonPath('data.snapshot.dues.note', 'Principal approved')
            ->assertJsonPath('data.snapshot.reason', 'Family moved to another city');
    }

    public function test_a_transfer_marks_the_student_and_logins_as_left(): void
    {
        $login = User::factory()->create(['is_active' => true]);
        $guardian = User::factory()->create(['is_active' => true]);
        $guardian->assignRole('parent');
        $student = $this->student(['user_id' => $login->id, 'guardian_user_id' => $guardian->id]);
        DB::table('attendances')->insert([
            'enrolment_id' => $student->enrolments()->value('id'), 'student_id' => $student->id, 'section_id' => $this->section10->id,
            'academic_year_id' => $this->year->id, 'date' => '2026-10-12', 'status' => 'present', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->transfer($student)->assertCreated()->assertJsonPath('data.snapshot.last_attendance_date', '2026-10-12');

        $student->refresh();
        $this->assertSame('left', $student->status);
        $this->assertSame('2026-10-15', $student->leaving_date->toDateString());
        $this->assertFalse((bool) $login->fresh()->is_active);
        $this->assertFalse((bool) $guardian->fresh()->is_active);
        $this->assertSame('left', $student->enrolments()->first()->status);
    }

    public function test_a_second_transfer_is_a_conflict_and_a_left_student_is_refused(): void
    {
        $student = $this->student();
        $this->transfer($student)->assertCreated();
        $this->transfer($student)->assertStatus(409);

        $left = $this->student(['status' => 'left', 'leaving_date' => '2026-06-30']);
        $this->transfer($left)->assertUnprocessable()->assertJsonValidationErrors(['student_id']);
    }

    public function test_cancelling_a_transfer_leaves_the_student_left(): void
    {
        $student = $this->student();
        $id = $this->transfer($student)->assertCreated()->json('data.id');

        $this->as($this->admin)->postJson("/api/certificates/{$id}/cancel", ['reason' => 'Issued twice by mistake'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancel_reason', 'Issued twice by mistake');

        $this->assertSame('left', $student->fresh()->status);
    }

    // Study certificate

    public function test_a_study_certificate_needs_an_active_student_enrolled_this_year(): void
    {
        $left = $this->student(['status' => 'left', 'leaving_date' => '2026-06-30']);
        $this->issue('study', $left)->assertUnprocessable()->assertJsonValidationErrors(['student_id']);

        // Enrolled only in an earlier year.
        $old = AcademicYear::factory()->create(['year' => 2025]);
        $student = Student::factory()->create();
        StudentEnrolment::factory()->create(['student_id' => $student->id, 'academic_year_id' => $old->id, 'section_id' => $this->section10->id]);
        $this->issue('study', $student)->assertUnprocessable()->assertJsonValidationErrors(['student_id']);

        $this->assertDatabaseCount('certificates', 0);
    }

    // Validation

    public function test_validation_errors(): void
    {
        $student = $this->student();

        $this->issue('bogus', $student)->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->issue('transfer', $student)->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->issue('testimonial', $student, ['gpa' => '5.5'])->assertUnprocessable()->assertJsonValidationErrors(['gpa']);
        $this->issue('character', $student, ['remarks' => str_repeat('a', 501)])->assertUnprocessable()->assertJsonValidationErrors(['remarks']);
        $this->issue('character', $student, ['conduct' => str_repeat('a', 501)])->assertUnprocessable()->assertJsonValidationErrors(['conduct']);
        $this->as($this->office)->postJson('/api/certificates', ['type' => 'character', 'student_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors(['student_id']);
        $this->as($this->office)->getJson('/api/certificates?type[]=x')->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->as($this->office)->getJson('/api/id-cards')->assertUnprocessable();
        $this->as($this->office)->getJson('/api/id-cards?section_id=1&student_id=1')->assertUnprocessable();
    }

    // Register

    public function test_the_register_lists_filters_and_omits_the_snapshot(): void
    {
        $a = $this->student(['name_en' => 'Alpha Student']);
        $b = $this->student(['name_en' => 'Beta Student']);
        $this->issue('character', $a)->assertCreated();
        $id = $this->issue('study', $b)->assertCreated()->json('data.id');
        $this->as($this->admin)->postJson("/api/certificates/{$id}/cancel", ['reason' => 'Wrong student'])->assertOk();

        $this->as($this->office)->getJson('/api/certificates')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'type', 'serial_no', 'status', 'student' => ['id', 'student_id', 'name_en', 'name_bn']]], 'links', 'meta' => ['total']])
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.snapshot');

        $this->as($this->office)->getJson('/api/certificates?type=study')->assertJsonCount(1, 'data');
        $this->as($this->office)->getJson('/api/certificates?status=cancelled')->assertJsonCount(1, 'data')->assertJsonPath('data.0.serial_no', 'STU-2026-0001');
        $this->as($this->office)->getJson('/api/certificates?status=issued')->assertJsonCount(1, 'data');
        $this->as($this->office)->getJson("/api/certificates?student_id={$a->id}")->assertJsonCount(1, 'data');
        $this->as($this->office)->getJson('/api/certificates?search=Beta')->assertJsonCount(1, 'data');
        $this->as($this->office)->getJson('/api/certificates?search=CHR-2026')->assertJsonCount(1, 'data');
        $this->as($this->office)->getJson('/api/certificates?from=2026-10-16')->assertJsonCount(0, 'data');
        $this->as($this->office)->getJson('/api/certificates?from=2026-10-15&to=2026-10-15')->assertJsonCount(2, 'data');
        $this->as($this->office)->getJson("/api/certificates?academic_year_id={$this->year->id}")->assertJsonCount(2, 'data');
    }

    public function test_an_unknown_certificate_is_a_404(): void
    {
        $this->as($this->office)->getJson('/api/certificates/999999')->assertNotFound();
        $this->as($this->admin)->postJson('/api/certificates/999999/cancel', ['reason' => 'x'])->assertNotFound();
        $this->as($this->office)->getJson('/api/certificates/1abc')->assertNotFound();
    }

    // Authorization

    public function test_issuing_and_cancelling_permissions(): void
    {
        $student = $this->student();

        $this->issue('character', $student, [], $this->teacher)->assertForbidden();

        $id = $this->issue('character', $student, [], $this->admin)->assertCreated()->json('data.id');

        $this->as($this->office)->postJson("/api/certificates/{$id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->as($this->admin)->postJson("/api/certificates/{$id}/cancel", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->as($this->admin)->postJson("/api/certificates/{$id}/cancel", ['reason' => 'Typo'])->assertOk();
        $this->as($this->admin)->postJson("/api/certificates/{$id}/cancel", ['reason' => 'Again'])->assertStatus(409);
    }

    public function test_a_teacher_reads_only_certificates_of_their_own_sections(): void
    {
        $mine = $this->student();
        $theirs = $this->student([], $this->section9);
        $mineId = $this->issue('character', $mine)->assertCreated()->json('data.id');
        $theirsId = $this->issue('character', $theirs)->assertCreated()->json('data.id');

        $teacher = $this->assignedTeacher($this->section10, $this->bangla);

        $this->as($teacher)->getJson('/api/certificates')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mineId);
        $this->as($teacher)->getJson("/api/certificates/{$mineId}")->assertOk()->assertJsonPath('data.snapshot.student.student_id', $mine->student_id);
        $this->as($teacher)->getJson("/api/certificates/{$theirsId}")->assertForbidden();
        $this->as($teacher)->postJson("/api/certificates/{$mineId}/cancel", ['reason' => 'x'])->assertForbidden();

        $this->as($this->userWithRole('teacher'))->getJson('/api/certificates')->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->office)->getJson("/api/certificates/{$theirsId}")->assertOk();
    }

    public function test_students_and_parents_get_403_everywhere(): void
    {
        $student = $this->student();
        $id = $this->issue('character', $student)->assertCreated()->json('data.id');

        foreach (['student', 'parent'] as $role) {
            $user = $this->userWithRole($role);

            $this->as($user)->getJson('/api/certificates')->assertForbidden();
            $this->as($user)->getJson("/api/certificates/{$id}")->assertForbidden();
            $this->as($user)->postJson('/api/certificates', ['type' => 'character', 'student_id' => $student->id])->assertForbidden();
            $this->as($user)->postJson("/api/certificates/{$id}/cancel", ['reason' => 'x'])->assertForbidden();
            $this->as($user)->getJson("/api/id-cards?student_id={$student->id}")->assertForbidden();
        }
    }

    // Guards

    public function test_a_student_or_year_with_certificates_cannot_be_deleted(): void
    {
        $student = $this->student();
        $this->issue('character', $student)->assertCreated();

        $this->as($this->admin)->deleteJson("/api/students/{$student->id}")->assertStatus(409);

        $other = AcademicYear::factory()->create(['year' => 2027]);
        Certificate::factory()->create(['student_id' => $student->id, 'academic_year_id' => $other->id]);
        $this->as($this->admin)->deleteJson("/api/academic-years/{$other->id}")->assertStatus(409);
    }

    // ID cards

    public function test_id_cards_for_a_section_come_by_roll_with_the_year_end(): void
    {
        $this->enrol($this->section10, null, null, 2, ['name_en' => 'Second', 'blood_group' => 'O+']);
        $this->enrol($this->section10, null, null, 1, ['name_en' => 'First', 'photo' => 'students/a.jpg']);
        $this->enrol($this->section10, null, null, 3, ['name_en' => 'Gone', 'status' => 'left', 'leaving_date' => '2026-06-30'])
            ->update(['status' => 'left']);

        $this->as($this->office)->getJson("/api/id-cards?section_id={$this->section10->id}")
            ->assertOk()
            ->assertJsonStructure(['data' => ['academic_year' => ['id'], 'school' => ['name_en', 'logo_url'], 'cards' => [['student_id', 'name_en', 'class_name', 'section', 'shift_name_en', 'roll_number', 'group', 'blood_group', 'guardian_mobile', 'photo_url', 'valid_until']]]])
            ->assertJsonCount(2, 'data.cards')
            ->assertJsonPath('data.cards.0.name_en', 'First')
            ->assertJsonPath('data.cards.1.name_en', 'Second')
            ->assertJsonPath('data.cards.0.valid_until', $this->year->end_date->toDateString())
            ->assertJsonPath('data.cards.1.photo_url', null)
            ->assertJsonPath('data.cards.1.blood_group', 'O+');

        $this->assertStringStartsWith('http', $this->as($this->office)->getJson("/api/id-cards?section_id={$this->section10->id}")->json('data.cards.0.photo_url'));
    }

    public function test_an_id_card_for_one_student_and_the_teacher_scope(): void
    {
        $student = $this->student(['name_en' => 'Solo']);
        $other = $this->student([], $this->section9);

        $this->as($this->office)->getJson("/api/id-cards?student_id={$student->id}")
            ->assertOk()->assertJsonCount(1, 'data.cards')->assertJsonPath('data.cards.0.name_en', 'Solo');
        $this->as($this->office)->getJson('/api/id-cards?student_id=999999')->assertNotFound();

        $teacher = $this->assignedTeacher($this->section10, $this->bangla);
        $this->as($teacher)->getJson("/api/id-cards?section_id={$this->section10->id}")->assertOk()->assertJsonCount(1, 'data.cards');
        $this->as($teacher)->getJson("/api/id-cards?student_id={$student->id}")->assertOk();
        $this->as($teacher)->getJson("/api/id-cards?section_id={$this->section9->id}")->assertForbidden();
        $this->as($teacher)->getJson("/api/id-cards?student_id={$other->id}")->assertForbidden();
    }

    public function test_only_the_guardian_mobile_is_hidden_from_a_teacher(): void
    {
        $this->student(['guardian_mobile' => '01712345678']);
        $teacher = $this->assignedTeacher($this->section10, $this->bangla);

        $this->as($teacher)->getJson("/api/id-cards?section_id={$this->section10->id}")
            ->assertOk()->assertJsonMissingPath('data.cards.0.guardian_mobile')->assertJsonPath('data.cards.0.class_name', 'Class 10');
        $this->as($this->admin)->getJson("/api/id-cards?section_id={$this->section10->id}")
            ->assertOk()->assertJsonPath('data.cards.0.guardian_mobile', '01712345678');
    }

    public function test_a_teachers_scope_is_the_active_year_only(): void
    {
        $teacher = $this->assignedTeacher($this->section10, $this->bangla);
        $past = AcademicYear::factory()->create(['year' => 2025]);
        $student = $this->student();
        $old = StudentEnrolment::factory()->create(['student_id' => $student->id, 'academic_year_id' => $past->id, 'section_id' => $this->section10->id, 'roll_number' => 9]);
        $certificate = Certificate::factory()->create(['student_id' => $student->id, 'enrolment_id' => $old->id, 'academic_year_id' => $past->id]);

        $this->as($teacher)->getJson("/api/certificates/{$certificate->id}")->assertForbidden();
        $this->as($teacher)->getJson('/api/certificates')->assertOk()->assertJsonCount(0, 'data');
        $this->as($this->office)->getJson("/api/certificates/{$certificate->id}")->assertOk();
    }

    // Outstanding fees: only what has fallen due by the issue month

    public function test_a_future_months_due_does_not_block_a_transfer_but_the_current_months_does(): void
    {
        $student = $this->student();
        $enrolment = $student->enrolments()->first();
        FeeDue::factory()->create(['enrolment_id' => $enrolment->id, 'period' => '2026-11', 'due_date' => '2026-11-10']);
        FeeDue::factory()->create(['enrolment_id' => $enrolment->id, 'period' => 'one_time', 'due_date' => '2026-11-05']);

        $this->transfer($student)->assertCreated()->assertJsonPath('data.snapshot.dues.outstanding', '0.00');

        $other = $this->student();
        FeeDue::factory()->create(['enrolment_id' => $other->enrolments()->first()->id, 'period' => '2026-11', 'due_date' => '2026-11-10']);
        FeeDue::factory()->create(['enrolment_id' => $other->enrolments()->first()->id, 'period' => '2026-10', 'due_date' => '2026-10-31']);

        $this->transfer($other)->assertStatus(409)->assertJsonPath('outstanding', '800.00');

        $this->transfer($other, ['allow_outstanding' => true, 'outstanding_note' => 'Agreed'])
            ->assertCreated()->assertJsonPath('data.snapshot.dues.outstanding', '800.00');
    }
}
