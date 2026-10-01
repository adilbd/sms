<?php

namespace Tests\Feature;

use App\Models\ClassSubject;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\User;
use Database\Seeders\AcademicYearSeeder;
use Database\Seeders\ClassSeeder;
use Database\Seeders\CurriculumSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SectionSeeder;
use Database\Seeders\ShiftSeeder;
use Database\Seeders\StudentSeeder;
use Database\Seeders\SubjectSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StudentSeederTest extends TestCase
{
    use RefreshDatabase;

    private function seedPrerequisites(): void
    {
        $this->seed([
            RolePermissionSeeder::class, ShiftSeeder::class, ClassSeeder::class, SubjectSeeder::class,
            CurriculumSeeder::class, AcademicYearSeeder::class, SectionSeeder::class,
        ]);
    }

    public function test_seeds_five_students_per_class_with_valid_enrolments_and_logins(): void
    {
        $this->seedPrerequisites();

        $this->seed(StudentSeeder::class);

        $this->assertSame(60, Student::count());
        $this->assertSame(60, StudentEnrolment::count());

        foreach (StudentEnrolment::with(['class', 'section.shift'])->get() as $enrolment) {
            $this->assertSame('A', $enrolment->section->code);
            $this->assertSame('morning', $enrolment->section->shift->slug);

            if ($enrolment->class->hasGroups()) {
                $this->assertNotNull($enrolment->group, "Class {$enrolment->class->number} needs a group");

                if ($enrolment->optional_subject_id) {
                    $this->assertTrue(
                        ClassSubject::where('class_id', $enrolment->class_id)
                            ->where('subject_id', $enrolment->optional_subject_id)
                            ->where('type', ClassSubject::TYPE_OPTIONAL)
                            ->where(fn ($q) => $q->whereNull('group')->orWhere('group', $enrolment->group))
                            ->exists()
                    );
                }
            } else {
                $this->assertNull($enrolment->group);
                $this->assertNull($enrolment->optional_subject_id);
            }
        }

        // Class 9 and up really do get a 4th subject from the sample curriculum.
        $this->assertTrue(StudentEnrolment::whereNotNull('optional_subject_id')->exists());
        $this->assertSame(0, StudentEnrolment::whereHas('class', fn ($q) => $q->where('number', '<', 9))->whereNotNull('group')->count());
    }

    public function test_siblings_share_one_guardian_login_and_everyone_can_sign_in(): void
    {
        $this->seedPrerequisites();
        $this->seed(StudentSeeder::class);

        $guardianWithTwo = Student::select('guardian_user_id')->groupBy('guardian_user_id')->havingRaw('count(*) > 1')->count();
        $this->assertGreaterThan(0, $guardianWithTwo);

        $this->postJson('/api/login', ['login' => '20260001', 'password' => 'password'])->assertOk()->assertJsonPath('data.user.roles', ['student']);
        $this->postJson('/api/login', ['login' => '+8801999000001', 'password' => 'password'])->assertOk()->assertJsonPath('data.user.roles', ['parent']);
    }

    public function test_running_twice_creates_no_duplicates(): void
    {
        $this->seedPrerequisites();

        $this->seed(StudentSeeder::class);
        $users = User::count();
        $this->seed(StudentSeeder::class);

        $this->assertSame(60, Student::count());
        $this->assertSame(60, StudentEnrolment::count());
        $this->assertSame($users, User::count());
    }

    public function test_does_nothing_without_the_2026_year(): void
    {
        $this->seed([RolePermissionSeeder::class, ShiftSeeder::class, ClassSeeder::class]);

        $this->seed(StudentSeeder::class);

        $this->assertSame(0, Student::count());
    }

    public function test_role_permission_seeder_can_run_twice_and_the_parent_cannot_list_students(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(1, User::where('email', 'admin@sms.com')->count());
        $this->assertSame(5, \Spatie\Permission\Models\Role::count());
        $this->assertSame(Permission::count(), Permission::distinct('name')->count('name'));
        $this->assertFalse(\Spatie\Permission\Models\Role::findByName('parent')->hasPermissionTo('view-students'));
        $this->assertFalse(Permission::where('name', 'like', '%-parents')->exists());
        $this->assertTrue(User::where('email', 'admin@sms.com')->firstOrFail()->hasRole('admin'));
    }
}
