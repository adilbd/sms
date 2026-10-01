<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyRecordsApiTest extends TestCase
{
    use RefreshDatabase;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->year = AcademicYear::factory()->active()->create();
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function studentFor(User $login, ?User $guardian = null): Student
    {
        $student = Student::factory()->create(['user_id' => $login->id, 'guardian_user_id' => $guardian?->id]);
        $section = Section::factory()->create();
        StudentEnrolment::factory()->create([
            'student_id' => $student->id, 'academic_year_id' => $this->year->id,
            'section_id' => $section->id, 'class_id' => $section->class_id, 'roll_number' => 3,
        ]);

        return $student;
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/my/student')->assertUnauthorized();
        $this->getJson('/api/my/children')->assertUnauthorized();
    }

    public function test_a_student_sees_only_their_own_record(): void
    {
        $mine = $this->userWithRole('student');
        $me = $this->studentFor($mine);
        $this->studentFor($this->userWithRole('student'));

        $this->actingAs($mine, 'sanctum')
            ->getJson('/api/my/student')
            ->assertOk()
            ->assertJsonPath('data.id', $me->id)
            ->assertJsonPath('data.current_enrolment.roll_number', 3)
            ->assertJsonStructure(['data' => ['student_id', 'username', 'guardian', 'enrolments']]);
    }

    public function test_a_student_without_a_record_gets_404(): void
    {
        $this->actingAs($this->userWithRole('student'), 'sanctum')
            ->getJson('/api/my/student')
            ->assertNotFound();
    }

    public function test_a_guardian_sees_only_their_own_children_including_siblings(): void
    {
        $guardian = $this->userWithRole('parent');
        $first = $this->studentFor($this->userWithRole('student'), $guardian);
        $second = $this->studentFor($this->userWithRole('student'), $guardian);
        $this->studentFor($this->userWithRole('student'), $this->userWithRole('parent'));

        $response = $this->actingAs($guardian, 'sanctum')
            ->getJson('/api/my/children')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertEqualsCanonicalizing([$first->id, $second->id], array_column($response->json('data'), 'id'));
    }

    public function test_a_guardian_does_not_see_deleted_or_other_guardians_children(): void
    {
        $guardian = $this->userWithRole('parent');
        $gone = $this->studentFor($this->userWithRole('student'), $guardian);
        $gone->delete();

        $this->actingAs($guardian, 'sanctum')
            ->getJson('/api/my/children')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_each_role_gets_403_on_the_other_roles_endpoint(): void
    {
        $student = $this->userWithRole('student');
        $parent = $this->userWithRole('parent');
        $teacher = $this->userWithRole('teacher');

        $this->actingAs($student, 'sanctum')->getJson('/api/my/children')->assertForbidden();
        $this->actingAs($parent, 'sanctum')->getJson('/api/my/student')->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->getJson('/api/my/student')->assertForbidden();
        $this->actingAs($teacher, 'sanctum')->getJson('/api/my/children')->assertForbidden();
    }
}
