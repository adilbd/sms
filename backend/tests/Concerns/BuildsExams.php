<?php

namespace Tests\Concerns;

use App\Models\AcademicYear;
use App\Models\Classes;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\Section;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentEnrolment;
use App\Models\Subject;
use App\Models\SubjectAssignment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

/**
 * A small school for exam tests: the active 2026 year, Class 9 (Bangla common and paired,
 * Physics and Higher Math for Science, Accounting for Business Studies) and Class 10
 * (Bangla only), each with a Section A in one shift.
 */
trait BuildsExams
{
    protected User $admin;

    protected AcademicYear $year;

    protected Shift $shift;

    protected Classes $class9;

    protected Classes $class10;

    protected Section $section9;

    protected Subject $bangla;

    protected Subject $physics;

    protected Subject $accounting;

    protected Subject $higherMath;

    protected function setUpExams(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@sms.com')->firstOrFail();

        $this->year = AcademicYear::factory()->active()->create(['year' => 2026]);
        $this->shift = Shift::factory()->create();
        $this->class9 = Classes::factory()->create(['number' => 9, 'name' => 'Class 9']);
        $this->class10 = Classes::factory()->create(['number' => 10, 'name' => 'Class 10']);
        $this->section9 = Section::factory()->create(['class_id' => $this->class9->id, 'shift_id' => $this->shift->id]);

        $this->bangla = Subject::factory()->create(['name' => 'Bangla']);
        $this->physics = Subject::factory()->create(['name' => 'Physics']);
        $this->accounting = Subject::factory()->create(['name' => 'Accounting']);
        $this->higherMath = Subject::factory()->create(['name' => 'Higher Math']);

        $this->curriculum($this->class9, $this->bangla, null, 'compulsory', ['written_full' => 70, 'written_pass' => 23, 'mcq_full' => 30, 'mcq_pass' => 10, 'paper_group' => 'bangla']);
        $this->curriculum($this->class9, $this->physics, 'science', 'compulsory', [
            'written_full' => 50, 'written_pass' => 17, 'mcq_full' => 25, 'mcq_pass' => 8, 'practical_full' => 25, 'practical_pass' => 8,
        ]);
        $this->curriculum($this->class9, $this->accounting, 'business_studies', 'compulsory', ['written_full' => 70, 'written_pass' => 23, 'mcq_full' => 30, 'mcq_pass' => 10]);
        $this->curriculum($this->class9, $this->higherMath, 'science', 'optional', ['written_full' => 70, 'written_pass' => 23, 'mcq_full' => 30, 'mcq_pass' => 10]);
        $this->curriculum($this->class10, $this->bangla, null, 'compulsory', ['written_full' => 70, 'written_pass' => 23, 'mcq_full' => 30, 'mcq_pass' => 10]);
    }

    protected function as(User $user)
    {
        return $this->actingAs($user, 'sanctum');
    }

    protected function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    protected function curriculum(Classes $class, Subject $subject, ?string $group, string $type, array $marks = []): ClassSubject
    {
        return ClassSubject::factory()->create([
            'class_id' => $class->id, 'subject_id' => $subject->id, 'group' => $group, 'type' => $type, ...$marks,
        ]);
    }

    /**
     * Creates an exam through the API as an admin, for the given classes.
     *
     * @param  list<Classes>|null  $classes
     */
    protected function createExam(?array $classes = null, array $extra = []): Exam
    {
        $classes ??= [$this->class9, $this->class10];

        $id = $this->as($this->admin)->postJson('/api/exams', [
            'academic_year_id' => $this->year->id,
            'name_en' => 'Half Yearly Exam 2026',
            'code' => 'HY-2026',
            'type' => 'half_yearly',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-15',
            'class_ids' => array_map(fn (Classes $class) => $class->id, $classes),
            ...$extra,
        ])->assertCreated()->json('data.id');

        return Exam::findOrFail($id);
    }

    protected function enrol(Section $section, ?string $group = null, ?Subject $optional = null, ?int $roll = null, array $student = []): StudentEnrolment
    {
        return StudentEnrolment::factory()->create([
            'student_id' => Student::factory()->create($student)->id,
            'academic_year_id' => $this->year->id,
            'section_id' => $section->id,
            'group' => $group,
            'optional_subject_id' => $optional?->id,
            'roll_number' => $roll,
        ]);
    }

    /** A teacher whose staff record is linked to a login and assigned to the subject in the section. */
    protected function assignedTeacher(Section $section, Subject $subject, array $staff = []): User
    {
        $user = $this->userWithRole('teacher');
        $member = Staff::factory()->create(['user_id' => $user->id, ...$staff]);
        $member->shifts()->attach($section->shift_id);

        SubjectAssignment::factory()->create([
            'staff_id' => $member->id, 'subject_id' => $subject->id, 'section_id' => $section->id,
            'class_id' => $section->class_id, 'academic_year_id' => $this->year->id,
        ]);

        return $user;
    }
}
