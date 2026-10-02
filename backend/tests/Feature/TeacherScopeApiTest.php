<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\ClassSection;
use App\Models\Section;
use App\Models\Staff;
use App\Models\StudentEnrolment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsExams;
use Tests\TestCase;

/**
 * A signed-in teacher only sees their own work, enforced on the server: students and
 * sections limited to what they teach or lead, their own assignments, and their own exam
 * subjects (docs/tasks/staff-logins.md).
 */
class TeacherScopeApiTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private Section $section7;

    private Section $section6;

    private StudentEnrolment $in9;

    private StudentEnrolment $in7;

    private StudentEnrolment $in6;

    private User $physicsTeacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpExams();

        $this->section7 = Section::factory()->create(['class_id' => Classes::factory()->create(['number' => 7])->id, 'shift_id' => $this->shift->id]);
        $this->section6 = Section::factory()->create(['class_id' => Classes::factory()->create(['number' => 6])->id, 'shift_id' => $this->shift->id]);

        $this->in9 = $this->enrol($this->section9, 'science', null, 1, ['name_en' => 'Nine Student']);
        $this->in7 = $this->enrol($this->section7, null, null, 1, ['name_en' => 'Seven Student']);
        $this->in6 = $this->enrol($this->section6, null, null, 1, ['name_en' => 'Six Student']);

        $this->physicsTeacher = $this->assignedTeacher($this->section9, $this->physics);
    }

    /** @return list<int> */
    private function listedStudentIds(User $user, string $query = ''): array
    {
        return array_column($this->as($user)->getJson('/api/students'.$query)->assertOk()->json('data'), 'id');
    }

    private function leadSection(User $teacher, Section $section, ?AcademicYear $year = null): void
    {
        ClassSection::create([
            'class_id' => $section->class_id, 'section_id' => $section->id,
            'academic_year_id' => ($year ?? $this->year)->id,
            'staff_id' => Staff::where('user_id', $teacher->id)->firstOrFail()->id,
        ]);
    }

    // --- students ---------------------------------------------------------------------

    public function test_a_teacher_lists_only_students_in_the_sections_they_teach(): void
    {
        $this->assertSame([$this->in9->student_id], $this->listedStudentIds($this->physicsTeacher));
    }

    public function test_a_class_teacher_also_sees_the_section_they_lead(): void
    {
        $this->leadSection($this->physicsTeacher, $this->section7);

        $this->assertEqualsCanonicalizing(
            [$this->in9->student_id, $this->in7->student_id],
            $this->listedStudentIds($this->physicsTeacher)
        );
    }

    public function test_showing_a_student_outside_the_scope_is_forbidden(): void
    {
        $this->as($this->physicsTeacher)->getJson("/api/students/{$this->in9->student_id}")->assertOk();
        $this->as($this->physicsTeacher)->getJson("/api/students/{$this->in9->student_id}/enrolments")->assertOk();

        $this->as($this->physicsTeacher)->getJson("/api/students/{$this->in6->student_id}")->assertForbidden();
        $this->as($this->physicsTeacher)->getJson("/api/students/{$this->in6->student_id}/enrolments")->assertForbidden();
        $this->as($this->physicsTeacher)->getJson("/api/students/{$this->in7->student_id}")->assertForbidden();

        $this->leadSection($this->physicsTeacher, $this->section7);
        $this->as($this->physicsTeacher)->getJson("/api/students/{$this->in7->student_id}")->assertOk();
    }

    public function test_the_scope_cannot_be_widened_from_input(): void
    {
        $this->assertSame([], $this->listedStudentIds($this->physicsTeacher, "?section_id={$this->section6->id}"));
        $this->assertSame([], $this->listedStudentIds($this->physicsTeacher, "?class_id={$this->section6->class_id}"));
        $this->assertSame(
            [$this->in9->student_id],
            $this->listedStudentIds($this->physicsTeacher, "?scope_section_ids[]={$this->section6->id}&scope_section_ids[]={$this->section9->id}&search_sensitive=1")
        );
        $this->assertSame([$this->in9->student_id], $this->listedStudentIds($this->physicsTeacher, "?section_id={$this->section9->id}"));
    }

    public function test_a_past_year_uses_the_assignments_of_that_year(): void
    {
        $old = AcademicYear::factory()->create(['year' => 2025]);
        StudentEnrolment::factory()->create([
            'student_id' => $this->in6->student_id, 'academic_year_id' => $old->id,
            'section_id' => $this->section9->id, 'class_id' => $this->section9->class_id,
        ]);

        // Taught in 2026 only: the 2025 list is empty even though a student sat in 9-A then.
        $this->assertSame([], $this->listedStudentIds($this->physicsTeacher, "?academic_year_id={$old->id}"));
    }

    public function test_a_teacher_with_no_staff_link_or_no_assignments_sees_no_students(): void
    {
        $unlinked = $this->userWithRole('teacher');
        $idle = $this->userWithRole('teacher');
        Staff::factory()->create(['user_id' => $idle->id]);

        foreach ([$unlinked, $idle] as $teacher) {
            $this->assertSame([], $this->listedStudentIds($teacher));
            $this->as($teacher)->getJson("/api/students/{$this->in9->student_id}")->assertForbidden();
        }
    }

    public function test_an_admin_still_sees_everyone(): void
    {
        $this->assertEqualsCanonicalizing(
            [$this->in9->student_id, $this->in7->student_id, $this->in6->student_id],
            $this->listedStudentIds($this->admin)
        );
        $this->as($this->admin)->getJson("/api/students/{$this->in6->student_id}")->assertOk();
    }

    public function test_a_user_with_both_the_admin_and_teacher_roles_is_not_scoped(): void
    {
        $both = $this->assignedTeacher($this->section9, $this->bangla);
        $both->assignRole('admin');

        $this->assertCount(3, $this->listedStudentIds($both));
    }

    public function test_the_teacher_keeps_the_narrow_student_shape(): void
    {
        $this->as($this->physicsTeacher)->getJson("/api/students/{$this->in9->student_id}")
            ->assertOk()
            ->assertJsonMissingPath('data.birth_registration_number')
            ->assertJsonMissingPath('data.present_address')
            ->assertJsonMissingPath('data.guardian.mobile');
    }

    // --- sections ---------------------------------------------------------------------

    public function test_a_teacher_lists_only_their_own_sections(): void
    {
        $this->leadSection($this->physicsTeacher, $this->section7);

        $ids = array_column($this->as($this->physicsTeacher)->getJson('/api/sections?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertEqualsCanonicalizing([$this->section9->id, $this->section7->id], $ids);

        $all = array_column($this->as($this->admin)->getJson('/api/sections?per_page=100')->assertOk()->json('data'), 'id');
        $this->assertContains($this->section6->id, $all);
    }

    // --- my assignments ---------------------------------------------------------------

    public function test_my_assignments_lists_subjects_and_class_teacher_sections(): void
    {
        $this->leadSection($this->physicsTeacher, $this->section7);

        $this->as($this->physicsTeacher)->getJson('/api/my/assignments')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'academic_year' => ['id', 'year'],
                'subjects' => [['section' => ['id', 'name', 'class'], 'subject' => ['id', 'name'], 'class' => ['id', 'name']]],
                'class_teacher_of' => [['id', 'name', 'class_id', 'class']],
            ]])
            ->assertJsonPath('data.academic_year.id', $this->year->id)
            ->assertJsonCount(1, 'data.subjects')
            ->assertJsonPath('data.subjects.0.section.id', $this->section9->id)
            ->assertJsonPath('data.subjects.0.subject.id', $this->physics->id)
            ->assertJsonPath('data.subjects.0.class.id', $this->class9->id)
            ->assertJsonCount(1, 'data.class_teacher_of')
            ->assertJsonPath('data.class_teacher_of.0.id', $this->section7->id);
    }

    public function test_my_assignments_returns_every_led_section_with_is_main_and_students_of_all_of_them(): void
    {
        $this->leadSection($this->physicsTeacher, $this->section7);
        $staffId = Staff::where('user_id', $this->physicsTeacher->id)->firstOrFail()->id;
        ClassSection::where('staff_id', $staffId)->update(['is_main' => true]);
        ClassSection::create([
            'class_id' => $this->section6->class_id, 'section_id' => $this->section6->id,
            'academic_year_id' => $this->year->id, 'staff_id' => $staffId, 'is_main' => false,
        ]);

        $led = collect($this->as($this->physicsTeacher)->getJson('/api/my/assignments')->assertOk()->json('data.class_teacher_of'));

        $this->assertEqualsCanonicalizing([$this->section7->id, $this->section6->id], $led->pluck('id')->all());
        $this->assertTrue($led->firstWhere('id', $this->section7->id)['is_main']);
        $this->assertFalse($led->firstWhere('id', $this->section6->id)['is_main']);

        // Taught (9) plus both led sections.
        $this->assertEqualsCanonicalizing(
            [$this->in9->student_id, $this->in7->student_id, $this->in6->student_id],
            $this->listedStudentIds($this->physicsTeacher)
        );
    }

    public function test_my_assignments_for_a_given_year(): void
    {
        $old = AcademicYear::factory()->create(['year' => 2025]);

        $this->as($this->physicsTeacher)->getJson("/api/my/assignments?academic_year_id={$old->id}")
            ->assertOk()->assertJsonPath('data.academic_year.id', $old->id)->assertJsonCount(0, 'data.subjects');

        $this->as($this->physicsTeacher)->getJson('/api/my/assignments?academic_year_id=999999')->assertUnprocessable();
        $this->as($this->physicsTeacher)->getJson('/api/my/assignments?academic_year_id[]=1')->assertUnprocessable();
    }

    public function test_my_assignments_is_only_for_teachers(): void
    {
        $this->getJson('/api/my/assignments')->assertUnauthorized();
        $this->as($this->admin)->getJson('/api/my/assignments')->assertForbidden();
        foreach (['student', 'parent', 'office'] as $role) {
            $this->as($this->userWithRole($role))->getJson('/api/my/assignments')->assertForbidden();
        }
    }

    public function test_my_assignments_for_a_teacher_without_a_staff_link_is_an_empty_block(): void
    {
        $this->as($this->userWithRole('teacher'))->getJson('/api/my/assignments')
            ->assertOk()
            ->assertJsonPath('data.academic_year', null)
            ->assertJsonPath('data.subjects', [])
            ->assertJsonPath('data.class_teacher_of', []);
    }

    // --- mark sheets ------------------------------------------------------------------

    public function test_a_teacher_only_gets_their_assigned_exam_subjects(): void
    {
        $exam = $this->createExam();
        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/open-marks-entry")->assertOk();

        $subjectIds = fn (User $user, string $query = '') => array_column(
            $this->as($user)->getJson("/api/exams/{$exam->id}/subjects{$query}")->assertOk()->json('data'),
            'id'
        );
        $physicsRow = $exam->examSubjects()->where('subject_id', $this->physics->id)->where('class_id', $this->class9->id)->firstOrFail();

        $this->assertSame([$physicsRow->id], $subjectIds($this->physicsTeacher));
        $this->assertSame([$physicsRow->id], $subjectIds($this->physicsTeacher, "?class_id={$this->class9->id}"));
        $this->assertSame([], $subjectIds($this->physicsTeacher, "?class_id={$this->class10->id}"));
        $this->assertSame([], $subjectIds($this->userWithRole('teacher')));
        $this->assertGreaterThan(1, count($subjectIds($this->admin)));
    }

    public function test_the_mark_sheet_for_an_unassigned_subject_is_forbidden(): void
    {
        $exam = $this->createExam();
        $this->as($this->admin)->postJson("/api/exams/{$exam->id}/open-marks-entry")->assertOk();
        $bangla = $exam->examSubjects()->where('subject_id', $this->bangla->id)->where('class_id', $this->class9->id)->firstOrFail();
        $physics = $exam->examSubjects()->where('subject_id', $this->physics->id)->where('class_id', $this->class9->id)->firstOrFail();

        $this->as($this->physicsTeacher)->getJson("/api/exams/{$exam->id}/marks?section_id={$this->section9->id}&exam_subject_id={$bangla->id}")->assertForbidden();
        $this->as($this->physicsTeacher)->getJson("/api/exams/{$exam->id}/marks?section_id={$this->section9->id}&exam_subject_id={$physics->id}")->assertOk();
    }
}
