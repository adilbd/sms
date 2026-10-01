<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\Subject;
use App\Models\SubjectAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AcademicYear $year;

    private Section $section5;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@sms.com')->firstOrFail();
        $this->year = AcademicYear::factory()->create(['year' => 2026, 'is_active' => true]);
        $this->section5 = Section::factory()->create(['class_id' => Classes::factory()->create(['number' => 5])->id]);
    }

    /**
     * A valid Class 5 payload; nested arrays (enrolment) are merged, not replaced.
     */
    private function payload(array $override = []): array
    {
        return array_replace_recursive([
            'name_en' => 'Rahim Uddin',
            'name_bn' => 'রহিম উদ্দিন',
            'date_of_birth' => '2014-04-10',
            'gender' => 'male',
            'religion' => 'islam',
            'admission_date' => '2026-01-05',
            'guardian_relation' => 'father',
            'guardian_name' => 'Karim Uddin',
            'guardian_mobile' => '01711111111',
            'guardian_password' => 'guardian-pass',
            'password' => 'student-pass',
            'enrolment' => ['section_id' => $this->section5->id, 'roll_number' => 1],
        ], $override);
    }

    /**
     * A Class 9 section plus an optional 4th subject for the science group.
     *
     * @return array{0: Section, 1: Subject}
     */
    private function class9(?string $sectionGroup = null): array
    {
        $class = Classes::factory()->create(['number' => 9]);
        $section = Section::factory()->create(['class_id' => $class->id, 'group' => $sectionGroup]);
        $subject = Subject::factory()->create();
        ClassSubject::factory()->create([
            'class_id' => $class->id, 'subject_id' => $subject->id, 'group' => 'science', 'type' => ClassSubject::TYPE_OPTIONAL,
        ]);

        return [$section, $subject];
    }

    private function enrolStudent(Student $student, ?Section $section = null, array $attributes = []): StudentEnrolment
    {
        $section ??= $this->section5;

        return StudentEnrolment::factory()->create([
            'student_id' => $student->id,
            'academic_year_id' => $this->year->id,
            'section_id' => $section->id,
            'class_id' => $section->class_id,
            ...$attributes,
        ]);
    }

    /**
     * A teacher login who teaches a subject in $section (default: Class 5) this year, so
     * the teacher scope lets them see that section's students.
     */
    private function teacherOf(?Section $section = null): User
    {
        $section ??= $this->section5;
        $teacher = User::factory()->create();
        $teacher->assignRole('teacher');
        $staff = Staff::factory()->create(['user_id' => $teacher->id]);
        SubjectAssignment::factory()->create([
            'staff_id' => $staff->id, 'section_id' => $section->id, 'academic_year_id' => $this->year->id,
        ]);

        return $teacher;
    }

    // --- happy path -------------------------------------------------------------------

    public function test_store_creates_the_student_with_both_logins_and_an_enrolment(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload())
            ->assertCreated()
            ->assertJsonStructure(['data' => ['id', 'student_id', 'username', 'father', 'mother', 'guardian', 'current_enrolment', 'photo_url'], 'message'])
            ->assertJsonPath('data.student_id', '20260001')
            ->assertJsonPath('data.username', '20260001')
            ->assertJsonPath('data.guardian.mobile', '01711111111')
            ->assertJsonPath('data.current_enrolment.section_id', $this->section5->id)
            ->assertJsonPath('data.current_enrolment.roll_number', 1)
            ->assertJsonMissingPath('data.password');

        $student = Student::findOrFail($response->json('data.id'));
        $login = User::findOrFail($student->user_id);
        $guardian = User::findOrFail($student->guardian_user_id);

        $this->assertSame('20260001', $login->username);
        $this->assertTrue($login->hasRole('student'));
        $this->assertTrue(Hash::check('student-pass', $login->password));
        $this->assertSame('01711111111', $guardian->username);
        $this->assertTrue($guardian->hasRole('parent'));
        $this->assertTrue(Hash::check('guardian-pass', $guardian->password));
        $this->assertTrue($login->is_active && $guardian->is_active);
        $this->assertNull($guardian->email);
    }

    public function test_store_numbers_students_by_admission_year_and_sequence(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())
            ->assertCreated()->assertJsonPath('data.student_id', '20260001');
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload(['guardian_mobile' => '01722222222', 'enrolment' => ['roll_number' => 2]]))
            ->assertCreated()->assertJsonPath('data.student_id', '20260002');
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload(['admission_date' => '2027-01-03', 'guardian_mobile' => '01733333333', 'enrolment' => ['roll_number' => 3]]))
            ->assertCreated()->assertJsonPath('data.student_id', '20270001');
    }

    public function test_store_a_class_9_science_student_with_a_fourth_subject(): void
    {
        [$section, $subject] = $this->class9();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['enrolment' => [
                'section_id' => $section->id, 'group' => 'science', 'optional_subject_id' => $subject->id,
            ]]))
            ->assertCreated()
            ->assertJsonPath('data.current_enrolment.group', 'science')
            ->assertJsonPath('data.current_enrolment.optional_subject_id', $subject->id)
            ->assertJsonPath('data.current_enrolment.class.number', 9)
            ->assertJsonPath('data.username', '20260001');
    }

    public function test_store_uploads_a_photo(): void
    {
        Storage::fake('public');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->post('/api/students', array_merge($this->payload(), ['photo' => UploadedFile::fake()->image('p.jpg')]), ['Accept' => 'application/json'])
            ->assertCreated();

        $path = Student::findOrFail($response->json('data.id'))->photo;
        $this->assertStringStartsWith('students/', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($response->json('data.photo_url'));
    }

    public function test_index_defaults_to_the_active_year_and_paginates_under_meta(): void
    {
        $inYear = Student::factory()->create();
        $this->enrolStudent($inYear);
        $otherYear = AcademicYear::factory()->create();
        $past = Student::factory()->create();
        StudentEnrolment::factory()->create([
            'student_id' => $past->id, 'academic_year_id' => $otherYear->id,
            'section_id' => $this->section5->id, 'class_id' => $this->section5->class_id,
        ]);
        Student::factory()->create(); // never enrolled

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/students')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'student_id', 'username', 'current_enrolment' => ['section_id', 'class', 'section']]], 'links', 'meta' => ['total']])
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $inYear->id);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/students?academic_year_id={$otherYear->id}")
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $past->id);
    }

    public function test_index_filters_by_class_section_shift_group_status_and_search(): void
    {
        [$section9] = $this->class9();
        $a = Student::factory()->create(['name_en' => 'Anika Rahman', 'guardian_mobile' => '01755555555']);
        $b = Student::factory()->left()->create(['name_bn' => 'বিপুল', 'birth_registration_number' => '20140123456789012']);
        $this->enrolStudent($a);
        $this->enrolStudent($b, $section9, ['group' => 'science']);

        $get = fn (string $query) => $this->actingAs($this->admin, 'sanctum')->getJson("/api/students?{$query}");

        $get("class_id={$this->section5->class_id}")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $a->id);
        $get("section_id={$section9->id}")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $b->id);
        $get("shift_id={$section9->shift_id}")->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $b->id);
        $get('group=science')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $b->id);
        $get('status=left')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $b->id);
        $get('search=Anika')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $a->id);
        $get('search=01755555555')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $a->id);
        $get('search='.urlencode('বিপুল'))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $b->id);
        $get('search=20140123456789012')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $b->id);
        $get("search={$a->student_id}")->assertJsonPath('meta.total', 1);
        // The grouped OR must not escape the other filters.
        $get('search=Anika&status=left')->assertJsonPath('meta.total', 0);
    }

    public function test_search_by_guardian_mobile_or_birth_registration_needs_edit_students(): void
    {
        $a = Student::factory()->create(['name_en' => 'Anika Rahman', 'guardian_mobile' => '01755555555', 'birth_registration_number' => '20140123456789012']);
        $this->enrolStudent($a);
        $teacher = $this->teacherOf();

        foreach (['01755555555', '20140123456789012'] as $term) {
            $this->actingAs($teacher, 'sanctum')->getJson("/api/students?search={$term}")
                ->assertOk()->assertJsonPath('meta.total', 0);
            $this->actingAs($this->admin, 'sanctum')->getJson("/api/students?search={$term}")
                ->assertOk()->assertJsonPath('meta.total', 1);
        }

        // A caller cannot opt in through the query string.
        $this->actingAs($teacher, 'sanctum')->getJson('/api/students?search=01755555555&search_sensitive=1')
            ->assertOk()->assertJsonPath('meta.total', 0);
        // Names still work for a teacher.
        $this->actingAs($teacher, 'sanctum')->getJson('/api/students?search=Anika')
            ->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_index_rejects_an_array_search(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/students?search[]=x')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('search');
    }

    public function test_show_returns_the_enrolment_history(): void
    {
        $student = Student::factory()->create();
        $this->enrolStudent($student, null, ['roll_number' => 7]);
        $last = AcademicYear::factory()->create(['year' => 2025]);
        StudentEnrolment::factory()->create([
            'student_id' => $student->id, 'academic_year_id' => $last->id,
            'section_id' => $this->section5->id, 'class_id' => $this->section5->class_id, 'status' => 'promoted',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/students/{$student->id}")
            ->assertOk()
            ->assertJsonPath('data.current_enrolment.roll_number', 7)
            ->assertJsonCount(2, 'data.enrolments');

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/students/{$student->id}/enrolments")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.academic_year_id', $this->year->id)
            ->assertJsonPath('data.0.class.number', 5);
    }

    public function test_update_with_a_new_password_resets_the_login(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())->json('data.id');
        $student = Student::findOrFail($id);
        $login = User::findOrFail($student->user_id);
        $login->createToken('t');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/students/{$id}", ['password' => 'brand-new-pass', 'name_en' => 'Rahim U.'])
            ->assertOk()
            ->assertJsonPath('data.name_en', 'Rahim U.');

        $login->refresh();
        $this->assertTrue(Hash::check('brand-new-pass', $login->password));
        $this->assertSame('Rahim U.', $login->name);
        $this->assertSame(0, $login->tokens()->count());
        $this->postJson('/api/login', ['login' => '20260001', 'password' => 'brand-new-pass'])->assertOk();
    }

    public function test_update_without_a_password_keeps_the_login_password(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())->json('data.id');

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/students/{$id}", ['district' => 'Dhaka', 'password' => ''])->assertOk();

        $this->postJson('/api/login', ['login' => '20260001', 'password' => 'student-pass'])->assertOk();
    }

    public function test_update_can_move_the_enrolment_and_replace_or_remove_the_photo(): void
    {
        Storage::fake('public');
        $id = $this->actingAs($this->admin, 'sanctum')
            ->post('/api/students', array_merge($this->payload(), ['photo' => UploadedFile::fake()->image('p.jpg')]), ['Accept' => 'application/json'])
            ->json('data.id');
        $old = Student::findOrFail($id)->photo;
        $other = Section::factory()->create(['class_id' => $this->section5->class_id]);

        $this->actingAs($this->admin, 'sanctum')
            ->post("/api/students/{$id}", [
                '_method' => 'PUT', 'photo' => UploadedFile::fake()->image('q.jpg'),
                'enrolment' => ['section_id' => $other->id, 'roll_number' => 4],
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.current_enrolment.section_id', $other->id);

        Storage::disk('public')->assertMissing($old);
        $new = Student::findOrFail($id)->photo;
        Storage::disk('public')->assertExists($new);
        $this->assertSame(1, StudentEnrolment::where('student_id', $id)->count());

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/students/{$id}", ['remove_photo' => true])->assertOk()->assertJsonPath('data.photo_url', null);
        Storage::disk('public')->assertMissing($new);
    }

    public function test_destroy_returns_no_content_and_deactivates_both_logins(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())->json('data.id');
        $student = Student::findOrFail($id);

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/students/{$id}")->assertNoContent();

        $this->assertSoftDeleted($student);
        $this->assertFalse(User::findOrFail($student->user_id)->is_active);
        $this->assertFalse(User::findOrFail($student->guardian_user_id)->is_active);
        $this->assertSame(1, StudentEnrolment::where('student_id', $id)->count(), 'enrolments are kept as history');
    }

    public function test_destroy_keeps_a_shared_guardian_login_while_a_sibling_is_active(): void
    {
        $first = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())->json('data.id');
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload(['guardian_password' => null, 'enrolment' => ['roll_number' => 2]]))->assertCreated();
        $guardianId = Student::findOrFail($first)->guardian_user_id;

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/students/{$first}")->assertNoContent();

        $this->assertTrue(User::findOrFail($guardianId)->is_active);
    }

    // --- validation -------------------------------------------------------------------

    public function test_store_requires_the_core_fields(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'date_of_birth', 'gender', 'admission_date', 'guardian_relation', 'guardian_name',
                'guardian_mobile', 'password', 'enrolment',
            ]);
    }

    public function test_store_requires_at_least_one_name(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['name_en' => null, 'name_bn' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name_en');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['name_en' => null]))
            ->assertCreated();
    }

    public function test_group_is_forbidden_below_class_9(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['enrolment' => ['group' => 'science']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enrolment.group');
    }

    public function test_group_is_required_from_class_9(): void
    {
        [$section] = $this->class9();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['enrolment' => ['section_id' => $section->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enrolment.group');
    }

    public function test_group_must_match_the_sections_group(): void
    {
        [$section] = $this->class9('humanities');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['enrolment' => ['section_id' => $section->id, 'group' => 'science']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enrolment.group');
    }

    public function test_an_unknown_group_is_rejected(): void
    {
        [$section] = $this->class9();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['enrolment' => ['section_id' => $section->id, 'group' => 'arts']]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enrolment.group');
    }

    public function test_fourth_subject_must_be_an_optional_choice_for_the_class_and_group(): void
    {
        [$section, $subject] = $this->class9();
        $compulsory = Subject::factory()->create();
        ClassSubject::factory()->create(['class_id' => $section->class_id, 'subject_id' => $compulsory->id, 'group' => 'science']);
        $unrelated = Subject::factory()->create();

        foreach ([$compulsory->id, $unrelated->id] as $subjectId) {
            $this->actingAs($this->admin, 'sanctum')
                ->postJson('/api/students', $this->payload(['enrolment' => ['section_id' => $section->id, 'group' => 'science', 'optional_subject_id' => $subjectId]]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('enrolment.optional_subject_id');
        }

        // The science-only optional subject is not a choice for the humanities group.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['enrolment' => ['section_id' => $section->id, 'group' => 'humanities', 'optional_subject_id' => $subject->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enrolment.optional_subject_id');
    }

    public function test_fourth_subject_is_forbidden_below_class_9(): void
    {
        $subject = Subject::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['enrolment' => ['optional_subject_id' => $subject->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enrolment.optional_subject_id');
    }

    public function test_an_inactive_section_or_shift_is_rejected(): void
    {
        $inactiveSection = Section::factory()->create(['class_id' => $this->section5->class_id, 'is_active' => false]);
        $inactiveShift = Section::factory()->create(['class_id' => $this->section5->class_id, 'shift_id' => Shift::factory()->inactive()->create()->id]);

        foreach ([$inactiveSection, $inactiveShift] as $section) {
            $this->actingAs($this->admin, 'sanctum')
                ->postJson('/api/students', $this->payload(['enrolment' => ['section_id' => $section->id]]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('enrolment.section_id');
        }
    }

    public function test_an_unknown_or_deleted_section_is_rejected(): void
    {
        $deleted = Section::factory()->create(['class_id' => $this->section5->class_id]);
        $deleted->delete();

        foreach ([999999, $deleted->id] as $id) {
            $this->actingAs($this->admin, 'sanctum')
                ->postJson('/api/students', $this->payload(['enrolment' => ['section_id' => $id]]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('enrolment.section_id');
        }
    }

    public function test_a_duplicate_roll_number_in_the_same_section_and_year_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['guardian_password' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enrolment.roll_number');

        $this->assertSame(1, Student::count(), 'nothing is written when the enrolment is invalid');
        $this->assertSame(2, User::whereIn('username', ['20260001', '01711111111'])->count());
    }

    public function test_a_full_section_is_rejected(): void
    {
        $tiny = Section::factory()->create(['class_id' => $this->section5->class_id, 'capacity' => 1]);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['enrolment' => ['section_id' => $tiny->id]]))
            ->assertCreated();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['guardian_password' => null, 'enrolment' => ['section_id' => $tiny->id, 'roll_number' => 2]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enrolment.section_id');
    }

    public function test_store_needs_an_active_academic_year(): void
    {
        $this->year->update(['is_active' => false]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enrolment.section_id');
    }

    public function test_mobile_numbers_are_normalized_and_validated(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['guardian_mobile' => '+8801711111111', 'mobile' => '8801812345678']))
            ->assertCreated()
            ->assertJsonPath('data.guardian.mobile', '01711111111')
            ->assertJsonPath('data.mobile', '01812345678');

        foreach (['12345', '01211111111', '0171111111', 'abcdefghijk'] as $bad) {
            $this->actingAs($this->admin, 'sanctum')
                ->postJson('/api/students', $this->payload(['guardian_mobile' => $bad]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('guardian_mobile');
        }

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['father_mobile' => '123']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('father_mobile');
    }

    public function test_a_duplicate_or_malformed_birth_registration_number_is_rejected(): void
    {
        Student::factory()->create(['birth_registration_number' => '20140123456789012']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['birth_registration_number' => '20140123456789012']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('birth_registration_number');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['birth_registration_number' => '123']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('birth_registration_number');
    }

    public function test_a_non_active_status_needs_a_leaving_date_on_or_after_admission(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['status' => 'left']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leaving_date');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['leaving_date' => '2025-12-31']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leaving_date');
    }

    public function test_update_checks_the_leaving_date_against_saved_values(): void
    {
        $student = Student::factory()->create(['admission_date' => '2026-03-01']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/students/{$student->id}", ['status' => 'left'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leaving_date');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/students/{$student->id}", ['leaving_date' => '2026-02-01'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('leaving_date');
    }

    public function test_a_duplicate_login_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['email' => 'taken@example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_a_weak_password_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['password' => 'short']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_an_invalid_enum_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['gender' => 'x', 'religion' => 'y', 'blood_group' => 'Z', 'status' => 'gone', 'guardian_relation' => 'uncle']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['gender', 'religion', 'blood_group', 'status', 'guardian_relation']);
    }

    // --- guardian logins --------------------------------------------------------------

    public function test_siblings_with_the_same_guardian_mobile_share_one_parent_user(): void
    {
        $a = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())->assertCreated()->json('data');
        // The guardian already exists, so no guardian_password is needed.
        $b = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['name_en' => 'Sibling', 'guardian_password' => null, 'enrolment' => ['roll_number' => 2]]))
            ->assertCreated()->json('data');

        $this->assertSame($a['guardian']['user_id'], $b['guardian']['user_id']);
        $this->assertSame(1, User::where('username', '01711111111')->count());
        $this->assertNotSame($a['user_id'], $b['user_id']);
    }

    public function test_a_new_guardian_mobile_requires_a_guardian_password(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['guardian_password' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('guardian_password');

        $this->assertSame(0, Student::count());
        $this->assertSame(0, User::where('username', '20260001')->count(), 'the student login is rolled back too');
    }

    public function test_a_guardian_mobile_owned_by_a_non_parent_user_is_rejected(): void
    {
        $teacher = User::factory()->create(['username' => '01711111111']);
        $teacher->assignRole('teacher');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('guardian_mobile');
    }

    public function test_changing_the_guardian_mobile_relinks_and_deactivates_the_old_login(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())->json('data.id');
        $oldGuardian = User::where('username', '01711111111')->firstOrFail();

        // A new mobile without a password is refused, and the old link is untouched.
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/students/{$id}", ['guardian_mobile' => '01899999999'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('guardian_password');
        $this->assertSame($oldGuardian->id, Student::findOrFail($id)->guardian_user_id);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/students/{$id}", ['guardian_mobile' => '01899999999', 'guardian_password' => 'another-pass'])
            ->assertOk()
            ->assertJsonPath('data.guardian.mobile', '01899999999');

        $newGuardian = User::where('username', '01899999999')->firstOrFail();
        $this->assertSame($newGuardian->id, Student::findOrFail($id)->guardian_user_id);
        $this->assertTrue($newGuardian->hasRole('parent'));
        $this->assertFalse($oldGuardian->refresh()->is_active);
        $this->assertTrue($newGuardian->is_active);
    }

    public function test_relinking_keeps_the_old_guardian_active_while_a_sibling_remains(): void
    {
        $first = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())->json('data.id');
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload(['guardian_password' => null, 'enrolment' => ['roll_number' => 2]]))->assertCreated();
        $old = User::where('username', '01711111111')->firstOrFail();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/students/{$first}", ['guardian_mobile' => '01899999999', 'guardian_password' => 'another-pass'])
            ->assertOk();

        $this->assertTrue($old->refresh()->is_active);
    }

    public function test_update_with_a_guardian_password_resets_the_guardian_login(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())->json('data.id');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/students/{$id}", ['guardian_password' => 'reset-guardian-pass'])
            ->assertOk();

        $this->postJson('/api/login', ['login' => '01711111111', 'password' => 'reset-guardian-pass'])->assertOk();
    }

    public function test_marking_a_student_as_left_deactivates_the_logins_and_frees_the_seat(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())->json('data.id');
        $student = Student::findOrFail($id);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/students/{$id}", ['status' => 'left', 'leaving_date' => '2026-06-30'])
            ->assertOk()
            ->assertJsonPath('data.status', 'left')
            ->assertJsonPath('data.current_enrolment.status', 'left');

        $this->assertFalse(User::findOrFail($student->user_id)->is_active);
        $this->assertFalse(User::findOrFail($student->guardian_user_id)->is_active);
    }

    // --- authorization ----------------------------------------------------------------

    public function test_requires_authentication(): void
    {
        $student = Student::factory()->create();

        $this->getJson('/api/students')->assertUnauthorized();
        $this->postJson('/api/students', [])->assertUnauthorized();
        $this->getJson("/api/students/{$student->id}")->assertUnauthorized();
        $this->getJson("/api/students/{$student->id}/enrolments")->assertUnauthorized();
        $this->deleteJson("/api/students/{$student->id}")->assertUnauthorized();
    }

    public function test_a_teacher_can_read_but_not_write_students(): void
    {
        $teacher = $this->teacherOf();
        $student = Student::factory()->create();
        $this->enrolStudent($student);

        $this->actingAs($teacher, 'sanctum')->getJson('/api/students')->assertOk();
        $this->actingAs($teacher, 'sanctum')->getJson("/api/students/{$student->id}")->assertOk();
        $this->actingAs($teacher, 'sanctum')->getJson("/api/students/{$student->id}/enrolments")->assertOk();
        $this->actingAs($teacher, 'sanctum')->postJson('/api/students', [])->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->putJson("/api/students/{$student->id}", [])->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->deleteJson("/api/students/{$student->id}")->assertForbidden();
    }

    public function test_parent_and_student_roles_cannot_use_the_students_api(): void
    {
        $student = Student::factory()->create();

        foreach (['parent', 'student'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user, 'sanctum')->getJson('/api/students')->assertForbidden();
            $this->actingAs($user, 'sanctum')->getJson("/api/students/{$student->id}")->assertForbidden();
            $this->actingAs($user, 'sanctum')->getJson("/api/students/{$student->id}/enrolments")->assertForbidden();
        }
    }

    // --- conflicts and edge cases -----------------------------------------------------

    public function test_destroy_returns_409_when_the_student_has_attendance(): void
    {
        $student = Student::factory()->create();
        $enrolment = $this->enrolStudent($student);
        DB::table('attendances')->insert([
            'student_id' => $student->id,
            'enrolment_id' => $enrolment->id,
            'section_id' => $this->section5->id,
            'academic_year_id' => $this->year->id,
            'date' => '2026-02-01',
            'status' => 'present',
            'marked_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/students/{$student->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Student has attendance records and cannot be deleted.');

        $this->assertNotSoftDeleted($student);
    }

    public function test_subject_used_as_a_fourth_subject_cannot_be_deleted(): void
    {
        [$section, $subject] = $this->class9();
        $student = Student::factory()->create();
        $this->enrolStudent($student, $section, ['group' => 'science', 'optional_subject_id' => $subject->id]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/subjects/{$subject->id}")
            ->assertStatus(409);
    }

    public function test_non_numeric_id_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/students/1abc')->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/students/1abc/enrolments')->assertNotFound();
        $this->actingAs($this->admin, 'sanctum')->deleteJson('/api/students/1abc')->assertNotFound();
    }

    public function test_unknown_student_returns_404_without_leaking_the_model(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/students/999999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Record not found.');
    }

    // --- review round 1 ---------------------------------------------------------------

    public function test_deleting_a_student_frees_the_seat_and_the_roll_number(): void
    {
        $this->section5->update(['capacity' => 1]);
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())->json('data.id');

        // The section is full and roll 1 is taken.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['guardian_mobile' => '01722222222', 'enrolment' => ['roll_number' => 1]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enrolment.section_id');

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/students/{$id}")->assertNoContent();

        $this->assertSame('left', StudentEnrolment::where('student_id', $id)->value('status'));
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['guardian_mobile' => '01722222222', 'enrolment' => ['roll_number' => 1]]))
            ->assertCreated();
    }

    public function test_an_empty_enrolment_block_is_a_validation_error_not_a_500(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())->json('data.id');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/students/{$id}", ['enrolment' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enrolment');
        $this->actingAs($this->admin, 'sanctum')
            ->call('PUT', "/api/students/{$id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{"enrolment": {}}')
            ->assertUnprocessable();
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/students/{$id}", ['enrolment' => ['roll_number' => 4]])
            ->assertUnprocessable();
    }

    public function test_a_recycled_mobile_matching_an_inactive_guardian_needs_a_new_password(): void
    {
        $old = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())->json('data.id');
        $guardian = User::findOrFail(Student::findOrFail($old)->guardian_user_id);
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/students/{$old}")->assertNoContent();
        $this->assertFalse($guardian->refresh()->is_active);
        $guardian->createToken('old-device');

        $recycled = $this->payload(['guardian_name' => 'New Owner', 'guardian_password' => null, 'enrolment' => ['roll_number' => 2]]);
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $recycled)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('guardian_password');
        $this->assertFalse($guardian->refresh()->is_active);

        $recycled['guardian_password'] = 'brand-new-guardian';
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $recycled)->assertCreated();

        $guardian->refresh();
        $this->assertTrue($guardian->is_active);
        $this->assertSame('New Owner', $guardian->name);
        $this->assertTrue(Hash::check('brand-new-guardian', $guardian->password));
        $this->assertFalse(Hash::check('guardian-pass', $guardian->password));
        $this->assertSame(0, $guardian->tokens()->count(), 'old tokens are revoked');
    }

    public function test_the_guardian_login_name_follows_the_latest_guardian_name(): void
    {
        $first = $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())->json('data.id');
        $guardian = User::findOrFail(Student::findOrFail($first)->guardian_user_id);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/students/{$first}", ['guardian_name' => 'Renamed Guardian'])
            ->assertOk();
        $this->assertSame('Renamed Guardian', $guardian->refresh()->name);

        // A sibling registered later with the same mobile wins as the latest name.
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['guardian_name' => 'Sibling Guardian', 'guardian_password' => null, 'enrolment' => ['roll_number' => 2]]))
            ->assertCreated();
        $this->assertSame('Sibling Guardian', $guardian->refresh()->name);
    }

    public function test_an_email_differing_only_by_case_is_a_duplicate_and_is_stored_lowercase(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['email' => 'Taken@Example.COM']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['email' => 'Fresh@Example.COM']))
            ->assertCreated()
            ->assertJsonPath('data.email', 'fresh@example.com');

        $id = Student::firstOrFail()->id;
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/students/{$id}", ['email' => 'TAKEN@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_a_login_with_a_differently_cased_student_email_works(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['email' => 'Kid@Example.com']))->assertCreated();

        $this->postJson('/api/login', ['login' => 'KID@example.COM', 'password' => 'student-pass'])->assertOk();
    }

    public function test_sensitive_fields_are_only_sent_to_users_who_can_edit_students(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['birth_registration_number' => '20140123456789012', 'present_address' => 'Mirpur, Dhaka', 'father_mobile' => '01755555555']))
            ->assertCreated()
            ->assertJsonPath('data.birth_registration_number', '20140123456789012')
            ->json('data.id');

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/students/{$id}")
            ->assertOk()
            ->assertJsonPath('data.birth_registration_number', '20140123456789012')
            ->assertJsonPath('data.present_address', 'Mirpur, Dhaka')
            ->assertJsonPath('data.father.mobile', '01755555555')
            ->assertJsonPath('data.guardian.mobile', '01711111111')
            ->assertJsonPath('data.user_id', Student::findOrFail($id)->user_id);

        $teacher = $this->teacherOf();

        $hidden = ['birth_registration_number', 'present_address', 'permanent_address', 'user_id'];
        $show = $this->actingAs($teacher, 'sanctum')->getJson("/api/students/{$id}")->assertOk();
        foreach ($hidden as $key) {
            $show->assertJsonMissingPath("data.{$key}");
        }
        $show->assertJsonMissingPath('data.father.mobile')
            ->assertJsonMissingPath('data.mother.mobile')
            ->assertJsonMissingPath('data.guardian.mobile')
            ->assertJsonMissingPath('data.guardian.user_id')
            ->assertJsonPath('data.father.name_en', null)
            ->assertJsonPath('data.student_id', '20260001');

        $list = $this->actingAs($teacher, 'sanctum')->getJson('/api/students')->assertOk();
        $list->assertJsonMissingPath('data.0.birth_registration_number')
            ->assertJsonMissingPath('data.0.guardian.mobile')
            ->assertJsonMissingPath('data.0.present_address');

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/students')
            ->assertJsonPath('data.0.guardian.mobile', '01711111111');
    }

    public function test_the_next_student_id_does_not_wrap_after_9999(): void
    {
        Student::factory()->create(['student_id' => '20269999']);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/students', $this->payload())
            ->assertCreated()->assertJsonPath('data.student_id', '202610000');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/students', $this->payload(['guardian_mobile' => '01722222222', 'enrolment' => ['roll_number' => 2]]))
            ->assertCreated()->assertJsonPath('data.student_id', '202610001');
    }
}
