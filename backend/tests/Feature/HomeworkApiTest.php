<?php

namespace Tests\Feature;

use App\Models\ClassSubject;
use App\Models\Homework;
use App\Models\Section;
use App\Models\Staff;
use App\Models\StudentEnrolment;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsFees;
use Tests\Concerns\BuildsRoutines;
use Tests\TestCase;

/**
 * Homework for staff (/api/homework) and for students and guardians (/api/my/homework).
 * "Now" is 2026-10-15 12:00 in Asia/Dhaka (BuildsFees). Class 9 Section A ($section9) has
 * no group, so every curriculum row of Class 9 is allowed there.
 */
class HomeworkApiTest extends TestCase
{
    use BuildsFees, BuildsRoutines, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFees();
        $this->setUpRoutines();
        Storage::fake('local');
    }

    /** @return array<string, mixed> */
    private function payload(Section $section, Subject $subject, array $extra = []): array
    {
        return [
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'title' => 'Chapter 3 exercises',
            'details' => '<p>Solve all the questions.</p>',
            'assigned_on' => '2026-10-10',
            'due_on' => '2026-10-20',
            ...$extra,
        ];
    }

    private function homework(Section $section, Subject $subject, array $attributes = []): Homework
    {
        return Homework::factory()->create([
            'academic_year_id' => $this->year->id,
            'section_id' => $section->id,
            'subject_id' => $subject->id,
            'assigned_on' => '2026-10-10',
            'due_on' => '2026-10-20',
            ...$attributes,
        ]);
    }

    private function authoredBy(User $teacher, Section $section, Subject $subject, array $attributes = []): Homework
    {
        return $this->homework($section, $subject, ['staff_id' => Staff::where('user_id', $teacher->id)->value('id'), ...$attributes]);
    }

    private function pdf(string $name = 'sheet.pdf', int $kb = 100): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    // Assigning

    public function test_an_assigned_teacher_creates_homework(): void
    {
        $teacher = $this->assignedTeacher($this->section9, $this->bangla);

        $this->as($teacher)->postJson('/api/homework', $this->payload($this->section9, $this->bangla))
            ->assertCreated()
            ->assertJsonStructure(['data' => [
                'id', 'academic_year_id', 'section_id', 'subject_id', 'staff_id', 'title', 'details', 'assigned_on', 'due_on',
                'is_overdue', 'has_attachment', 'attachment_name', 'attachment_url', 'subject', 'staff', 'section',
            ], 'message'])
            ->assertJsonPath('data.title', 'Chapter 3 exercises')
            ->assertJsonPath('data.due_on', '2026-10-20')
            ->assertJsonPath('data.is_overdue', false)
            ->assertJsonPath('data.has_attachment', false)
            ->assertJsonPath('data.academic_year_id', $this->year->id)
            ->assertJsonPath('data.staff_id', Staff::where('user_id', $teacher->id)->value('id'));

        $this->assertDatabaseCount('homework', 1);
    }

    public function test_every_assigned_teacher_of_a_subject_may_assign(): void
    {
        $first = $this->assignedTeacher($this->section9, $this->bangla);
        $second = $this->assignedTeacher($this->section9, $this->bangla);

        $this->as($first)->postJson('/api/homework', $this->payload($this->section9, $this->bangla))->assertCreated();
        $this->as($second)->postJson('/api/homework', $this->payload($this->section9, $this->bangla))->assertCreated();
    }

    public function test_an_unassigned_teacher_gets_403(): void
    {
        // Assigned to Physics in this section and to Bangla in another one, but not to Bangla here.
        $teacher = $this->assignedTeacher($this->section9, $this->physics);
        $member = Staff::where('user_id', $teacher->id)->first();
        $member->shifts()->syncWithoutDetaching([$this->section10->shift_id]);
        $this->assign($member, $this->section10, $this->bangla);

        $this->as($teacher)->postJson('/api/homework', $this->payload($this->section9, $this->bangla))->assertForbidden();
        $this->as($this->userWithRole('teacher'))->postJson('/api/homework', $this->payload($this->section9, $this->bangla))->assertForbidden();
        $this->assertDatabaseCount('homework', 0);
    }

    public function test_a_retired_teacher_cannot_assign(): void
    {
        $teacher = $this->assignedTeacher($this->section9, $this->bangla);
        Staff::where('user_id', $teacher->id)->update(['status' => Staff::STATUS_RETIRED, 'leaving_date' => '2026-09-01']);

        $this->as($teacher)->postJson('/api/homework', $this->payload($this->section9, $this->bangla))->assertForbidden();
    }

    public function test_an_admin_assigns_for_any_section(): void
    {
        $this->as($this->admin)->postJson('/api/homework', $this->payload($this->section9, $this->bangla))
            ->assertCreated()->assertJsonPath('data.staff_id', null);
        $this->as($this->admin)->postJson('/api/homework', $this->payload($this->section10, $this->bangla))->assertCreated();
    }

    public function test_assigned_on_defaults_to_today_in_dhaka(): void
    {
        // 2026-10-15 20:00 UTC is already 16 October in Dhaka.
        $this->travelTo('2026-10-15 20:00:00');

        $this->as($this->admin)->postJson('/api/homework', $this->payload($this->section9, $this->bangla, ['assigned_on' => null, 'due_on' => '2026-10-20']))
            ->assertCreated()->assertJsonPath('data.assigned_on', '2026-10-16');
    }

    public function test_the_details_are_sanitized(): void
    {
        $this->as($this->admin)->postJson('/api/homework', $this->payload($this->section9, $this->bangla, [
            'details' => '<p>Read page 4</p><script>alert(1)</script>',
        ]))->assertCreated();

        $details = Homework::first()->details;
        $this->assertStringContainsString('Read page 4', $details);
        $this->assertStringNotContainsString('script', $details);

        $this->as($this->admin)->postJson('/api/homework', $this->payload($this->section9, $this->bangla, ['details' => '<script>alert(1)</script>']))
            ->assertUnprocessable()->assertJsonValidationErrors(['details']);
    }

    public function test_details_are_optional(): void
    {
        $this->as($this->admin)->postJson('/api/homework', $this->payload($this->section9, $this->bangla, ['details' => null]))
            ->assertCreated()->assertJsonPath('data.details', null);
    }

    // Validation

    public function test_required_fields_are_validated(): void
    {
        $this->as($this->admin)->postJson('/api/homework', [])
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id', 'subject_id', 'title', 'due_on']);

        $this->as($this->admin)->postJson('/api/homework', $this->payload($this->section9, $this->bangla, [
            'section_id' => 99999, 'title' => str_repeat('a', 256), 'due_on' => 'tomorrow',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['section_id', 'title', 'due_on']);
    }

    public function test_a_due_date_before_the_assigned_date_is_422(): void
    {
        $this->as($this->admin)->postJson('/api/homework', $this->payload($this->section9, $this->bangla, ['assigned_on' => '2026-10-10', 'due_on' => '2026-10-09']))
            ->assertUnprocessable()->assertJsonValidationErrors(['due_on']);

        $this->as($this->admin)->postJson('/api/homework', $this->payload($this->section9, $this->bangla, ['assigned_on' => '2026-10-10', 'due_on' => '2026-10-10']))
            ->assertCreated();
    }

    public function test_a_subject_outside_the_curriculum_is_422(): void
    {
        $other = Subject::factory()->create();

        $this->as($this->admin)->postJson('/api/homework', $this->payload($this->section9, $other))
            ->assertUnprocessable()->assertJsonValidationErrors(['subject_id']);
    }

    public function test_a_subject_for_another_group_is_422(): void
    {
        // Physics is a Science row; a Business Studies section does not teach it.
        $business = Section::factory()->create(['class_id' => $this->class9->id, 'shift_id' => $this->shift->id, 'group' => 'business_studies']);

        $this->as($this->admin)->postJson('/api/homework', $this->payload($business, $this->physics))
            ->assertUnprocessable()->assertJsonValidationErrors(['subject_id']);
        $this->as($this->admin)->postJson('/api/homework', $this->payload($business, $this->accounting))->assertCreated();
        $this->as($this->admin)->postJson('/api/homework', $this->payload($business, $this->bangla))->assertCreated();
    }

    public function test_the_attachment_must_be_a_pdf_or_image_up_to_5_mb(): void
    {
        $url = '/api/homework';
        $headers = ['Accept' => 'application/json'];

        $this->as($this->admin)->post($url, $this->payload($this->section9, $this->bangla, [
            'attachment' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
        ]), $headers)->assertUnprocessable()->assertJsonValidationErrors(['attachment']);

        $this->as($this->admin)->post($url, $this->payload($this->section9, $this->bangla, [
            'attachment' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ]), $headers)->assertUnprocessable()->assertJsonValidationErrors(['attachment']);

        $this->as($this->admin)->post($url, $this->payload($this->section9, $this->bangla, [
            'attachment' => $this->pdf('big.pdf', 5121),
        ]), $headers)->assertUnprocessable()->assertJsonValidationErrors(['attachment']);

        $this->assertDatabaseCount('homework', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->as($this->admin)->post($url, $this->payload($this->section9, $this->bangla, ['attachment' => $this->pdf('ok.pdf', 5120)]), $headers)->assertCreated();
        $this->as($this->admin)->post($url, $this->payload($this->section9, $this->bangla, ['attachment' => UploadedFile::fake()->image('photo.png')]), $headers)->assertCreated();
    }

    public function test_the_attachment_is_stored_on_the_private_disk(): void
    {
        $response = $this->as($this->admin)->post('/api/homework', $this->payload($this->section9, $this->bangla, [
            'attachment' => $this->pdf('Exercises.pdf'),
        ]), ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.has_attachment', true)
            ->assertJsonPath('data.attachment_name', 'Exercises.pdf');

        $path = Homework::first()->attachment_path;
        $this->assertStringStartsWith('homework/', $path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
        $this->assertStringNotContainsString($path, json_encode($response->json()));
    }

    // Editing

    public function test_the_author_edits_until_the_due_date(): void
    {
        $teacher = $this->assignedTeacher($this->section9, $this->bangla);
        $homework = $this->authoredBy($teacher, $this->section9, $this->bangla, ['due_on' => '2026-10-20']);

        $this->as($teacher)->putJson("/api/homework/{$homework->id}", ['title' => 'Changed', 'due_on' => '2026-10-25'])
            ->assertOk()->assertJsonPath('data.title', 'Changed')->assertJsonPath('data.due_on', '2026-10-25');

        // The due date itself is still inside the window (inclusive).
        $this->travelTo('2026-10-25 06:00:00');
        $this->as($teacher)->putJson("/api/homework/{$homework->id}", ['title' => 'Last day'])->assertOk();

        $this->travelTo('2026-10-25 19:00:00'); // already 26 October in Dhaka
        $this->as($teacher)->putJson("/api/homework/{$homework->id}", ['title' => 'Too late'])->assertForbidden();
        $this->assertSame('Last day', $homework->fresh()->title);
    }

    public function test_another_assigned_teacher_cannot_edit_or_delete_someone_elses_homework(): void
    {
        $author = $this->assignedTeacher($this->section9, $this->bangla);
        $other = $this->assignedTeacher($this->section9, $this->bangla);
        $homework = $this->authoredBy($author, $this->section9, $this->bangla);

        $this->as($other)->putJson("/api/homework/{$homework->id}", ['title' => 'Mine now'])->assertForbidden();
        $this->as($other)->deleteJson("/api/homework/{$homework->id}")->assertForbidden();
        $this->assertNotSame('Mine now', $homework->fresh()->title);
        $this->assertNull($homework->fresh()->deleted_at);
    }

    public function test_an_admin_edits_and_deletes_at_any_time(): void
    {
        $teacher = $this->assignedTeacher($this->section9, $this->bangla);
        $homework = $this->authoredBy($teacher, $this->section9, $this->bangla, ['due_on' => '2026-10-01', 'assigned_on' => '2026-09-20']);

        $this->as($teacher)->putJson("/api/homework/{$homework->id}", ['title' => 'Late'])->assertForbidden();
        $this->as($this->admin)->putJson("/api/homework/{$homework->id}", ['title' => 'Admin edit'])->assertOk()->assertJsonPath('data.title', 'Admin edit');
        $this->as($this->admin)->deleteJson("/api/homework/{$homework->id}")->assertNoContent();
        $this->assertSoftDeleted($homework);
    }

    public function test_the_author_deletes_before_the_due_date_but_not_after(): void
    {
        $teacher = $this->assignedTeacher($this->section9, $this->bangla);
        $current = $this->authoredBy($teacher, $this->section9, $this->bangla, ['due_on' => '2026-10-20']);
        $past = $this->authoredBy($teacher, $this->section9, $this->bangla, ['due_on' => '2026-10-14', 'assigned_on' => '2026-10-01']);

        $this->as($teacher)->deleteJson("/api/homework/{$past->id}")->assertForbidden();
        $this->as($teacher)->deleteJson("/api/homework/{$current->id}")->assertNoContent();
        $this->assertSoftDeleted($current);
        $this->assertNull($past->fresh()->deleted_at);
    }

    public function test_an_author_no_longer_assigned_cannot_edit(): void
    {
        $teacher = $this->assignedTeacher($this->section9, $this->bangla);
        $homework = $this->authoredBy($teacher, $this->section9, $this->bangla);
        \App\Models\SubjectAssignment::query()->delete();

        $this->as($teacher)->putJson("/api/homework/{$homework->id}", ['title' => 'Changed'])->assertForbidden();
    }

    public function test_section_subject_and_year_cannot_change(): void
    {
        $homework = $this->homework($this->section9, $this->bangla);

        $this->as($this->admin)->putJson("/api/homework/{$homework->id}", ['section_id' => $this->section10->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id']);
        $this->as($this->admin)->putJson("/api/homework/{$homework->id}", ['subject_id' => $this->physics->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['subject_id']);
    }

    public function test_the_due_date_is_checked_against_the_saved_assigned_date_on_update(): void
    {
        $homework = $this->homework($this->section9, $this->bangla, ['assigned_on' => '2026-10-10', 'due_on' => '2026-10-20']);

        // Only one of the two dates is sent.
        $this->as($this->admin)->putJson("/api/homework/{$homework->id}", ['due_on' => '2026-10-05'])
            ->assertUnprocessable()->assertJsonValidationErrors(['due_on']);
        $this->as($this->admin)->putJson("/api/homework/{$homework->id}", ['assigned_on' => '2026-10-25'])
            ->assertUnprocessable()->assertJsonValidationErrors(['due_on']);
    }

    public function test_the_attachment_can_be_replaced_and_removed(): void
    {
        $headers = ['Accept' => 'application/json'];
        $id = $this->as($this->admin)->post('/api/homework', $this->payload($this->section9, $this->bangla, ['attachment' => $this->pdf('one.pdf')]), $headers)
            ->assertCreated()->json('data.id');
        $first = Homework::find($id)->attachment_path;

        $this->as($this->admin)->post("/api/homework/{$id}", ['_method' => 'PUT', 'attachment' => $this->pdf('two.pdf')], $headers)
            ->assertOk()->assertJsonPath('data.attachment_name', 'two.pdf');
        $second = Homework::find($id)->attachment_path;
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $this->as($this->admin)->putJson("/api/homework/{$id}", ['title' => 'Only the title'])->assertOk()->assertJsonPath('data.has_attachment', true);
        Storage::disk('local')->assertExists($second);

        $this->as($this->admin)->putJson("/api/homework/{$id}", ['remove_attachment' => true])
            ->assertOk()->assertJsonPath('data.has_attachment', false)->assertJsonPath('data.attachment_url', null);
        Storage::disk('local')->assertMissing($second);
    }

    // Reading and listing

    public function test_show_returns_the_resource_and_unknown_ids_are_404(): void
    {
        $homework = $this->homework($this->section9, $this->bangla);

        $this->as($this->admin)->getJson("/api/homework/{$homework->id}")
            ->assertOk()->assertJsonPath('data.id', $homework->id)->assertJsonPath('data.subject.name', 'Bangla')
            ->assertJsonPath('data.section.class.number', 9);
        $this->as($this->admin)->getJson('/api/homework/999999')->assertNotFound();
        $this->as($this->admin)->getJson('/api/homework/1abc')->assertNotFound();
        $this->as($this->admin)->putJson('/api/homework/999999', ['title' => 'x'])->assertNotFound();
        $this->as($this->admin)->deleteJson('/api/homework/999999')->assertNotFound();
    }

    public function test_a_teacher_only_lists_their_own_sections(): void
    {
        $teacher = $this->assignedTeacher($this->section9, $this->bangla);
        $mine = $this->homework($this->section9, $this->bangla);
        $this->homework($this->section10, $this->bangla);

        $this->as($teacher)->getJson('/api/homework')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonStructure(['data', 'links', 'meta' => ['total', 'per_page']]);

        // Asking for the other section is not a way around the scope.
        $this->as($teacher)->getJson('/api/homework?section_id='.$this->section10->id)->assertOk()->assertJsonCount(0, 'data');

        $this->as($this->admin)->getJson('/api/homework')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_teacher_cannot_open_homework_of_another_section(): void
    {
        $teacher = $this->assignedTeacher($this->section9, $this->bangla);
        $foreign = $this->homework($this->section10, $this->bangla);

        $this->as($teacher)->getJson("/api/homework/{$foreign->id}")->assertForbidden();
    }

    public function test_the_list_filters(): void
    {
        $upcoming = $this->homework($this->section9, $this->bangla, ['due_on' => '2026-10-15', 'assigned_on' => '2026-10-01']);
        $later = $this->homework($this->section9, $this->physics, ['due_on' => '2026-10-30']);
        $past = $this->homework($this->section9, $this->bangla, ['due_on' => '2026-10-14', 'assigned_on' => '2026-10-01']);
        $this->homework($this->section10, $this->bangla, ['due_on' => '2026-10-20']);

        $ids = fn (string $query) => collect($this->as($this->admin)->getJson("/api/homework?{$query}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$later->id, $upcoming->id, $past->id], $ids('section_id='.$this->section9->id));
        $this->assertSame([$later->id], $ids('subject_id='.$this->physics->id));
        $this->assertSame([$later->id, $upcoming->id], $ids('section_id='.$this->section9->id.'&due=upcoming'));
        $this->assertSame([$past->id], $ids('section_id='.$this->section9->id.'&due=past'));
        $this->assertSame([$upcoming->id], $ids('section_id='.$this->section9->id.'&from=2026-10-15&to=2026-10-15'));
        $this->assertSame([$later->id], $ids('section_id='.$this->section9->id.'&from=2026-10-16'));

        $this->as($this->admin)->getJson('/api/homework?due=sometime')->assertUnprocessable()->assertJsonValidationErrors(['due']);
        $this->as($this->admin)->getJson('/api/homework?section_id[]=1')->assertUnprocessable();
        $this->as($this->admin)->getJson('/api/homework?from=2026-10-10&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
    }

    public function test_the_list_defaults_to_the_active_year(): void
    {
        $old = \App\Models\AcademicYear::factory()->create(['year' => 2025]);
        $this->homework($this->section9, $this->bangla);
        $this->homework($this->section9, $this->bangla, ['academic_year_id' => $old->id]);

        $this->as($this->admin)->getJson('/api/homework')->assertJsonCount(1, 'data');
        $this->as($this->admin)->getJson('/api/homework?academic_year_id='.$old->id)->assertJsonCount(1, 'data');
    }

    // Permissions

    public function test_students_guardians_and_guests_cannot_use_the_staff_endpoints(): void
    {
        $homework = $this->homework($this->section9, $this->bangla);

        $this->getJson('/api/homework')->assertUnauthorized();
        $this->getJson("/api/homework/{$homework->id}/attachment")->assertUnauthorized();

        foreach (['student', 'parent', 'office'] as $role) {
            $user = $this->userWithRole($role);
            $this->as($user)->getJson('/api/homework')->assertForbidden();
            $this->as($user)->getJson("/api/homework/{$homework->id}")->assertForbidden();
            $this->as($user)->postJson('/api/homework', $this->payload($this->section9, $this->bangla))->assertForbidden();
            $this->as($user)->putJson("/api/homework/{$homework->id}", ['title' => 'x'])->assertForbidden();
            $this->as($user)->deleteJson("/api/homework/{$homework->id}")->assertForbidden();
            $this->as($user)->getJson("/api/homework/{$homework->id}/attachment")->assertForbidden();
        }
    }

    // Attachments

    public function test_staff_download_the_attachment(): void
    {
        $teacher = $this->assignedTeacher($this->section9, $this->bangla);
        $id = $this->as($this->admin)->post('/api/homework', $this->payload($this->section9, $this->bangla, ['attachment' => $this->pdf('Exercises.pdf')]), ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        foreach ([$this->admin, $teacher] as $user) {
            $this->as($user)->get("/api/homework/{$id}/attachment")
                ->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Content-Disposition', 'attachment; filename=Exercises.pdf');
        }

        $this->as($this->admin)->get("/api/homework/{$id}/attachment")->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $this->as($this->admin)->get("/api/homework/{$id}/attachment")->headers->get('Cache-Control'));
    }

    public function test_an_image_attachment_is_shown_inline(): void
    {
        $id = $this->as($this->admin)->post('/api/homework', $this->payload($this->section9, $this->bangla, ['attachment' => UploadedFile::fake()->image('diagram.png')]), ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        $this->as($this->admin)->get("/api/homework/{$id}/attachment")->assertOk()->assertHeader('Content-Disposition', 'inline; filename=diagram.png');
    }

    public function test_a_teacher_outside_the_section_cannot_download_the_attachment(): void
    {
        $outsider = $this->assignedTeacher($this->section10, $this->bangla);
        $id = $this->as($this->admin)->post('/api/homework', $this->payload($this->section9, $this->bangla, ['attachment' => $this->pdf()]), ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        $this->as($outsider)->get("/api/homework/{$id}/attachment")->assertForbidden();
    }

    public function test_a_homework_without_an_attachment_is_404(): void
    {
        $homework = $this->homework($this->section9, $this->bangla);

        $this->as($this->admin)->getJson("/api/homework/{$homework->id}/attachment")->assertNotFound();
    }

    // My homework: students and guardians

    /** Class 9 Science: Biology (compulsory) and Higher Math (optional) are a choice pair. */
    private function sciencePair(): Subject
    {
        $biology = Subject::factory()->create(['name' => 'Biology', 'code' => 'BIO-T']);
        $this->curriculum($this->class9, $biology, 'science', 'compulsory', ['choice_group' => 'science-4th']);
        ClassSubject::where('class_id', $this->class9->id)->where('subject_id', $this->higherMath->id)->update(['choice_group' => 'science-4th']);

        return $biology;
    }

    private function loginStudent(StudentEnrolment $enrolment): User
    {
        $user = $this->userWithRole('student');
        $enrolment->student->update(['user_id' => $user->id]);

        return $user;
    }

    private function loginGuardian(StudentEnrolment ...$children): User
    {
        $user = $this->userWithRole('parent');

        foreach ($children as $child) {
            $child->student->update(['guardian_user_id' => $user->id]);
        }

        return $user;
    }

    /** @return list<int> */
    private function mySubjectIds(User $user, string $query = ''): array
    {
        return collect($this->as($user)->getJson('/api/my/homework'.$query)->assertOk()->json('data'))->pluck('subject_id')->sort()->values()->all();
    }

    public function test_a_student_sees_only_the_subjects_they_take(): void
    {
        $biology = $this->sciencePair();
        $subjects = [$this->bangla, $this->physics, $this->accounting, $this->higherMath, $biology];

        foreach ($subjects as $subject) {
            $this->homework($this->section9, $subject);
        }
        // Another section's homework never shows.
        $this->homework($this->section10, $this->bangla);

        $none = $this->enrol($this->section9, 'science', null, 1);
        $bioFourth = $this->enrol($this->section9, 'science', $biology, 2);
        $hmFourth = $this->enrol($this->section9, 'science', $this->higherMath, 3);
        $business = $this->enrol($this->section9, 'business_studies', null, 4);

        $sorted = fn (Subject ...$list) => collect($list)->pluck('id')->sort()->values()->all();

        // Compulsory common, plus the group's compulsory rows; no 4th subject, so no Higher Math.
        $this->assertSame($sorted($this->bangla, $this->physics, $biology), $this->mySubjectIds($this->loginStudent($none)));
        // Biology as the 4th subject still shows Higher Math (the pair's other member).
        $this->assertSame($sorted($this->bangla, $this->physics, $biology, $this->higherMath), $this->mySubjectIds($this->loginStudent($bioFourth)));
        $this->assertSame($sorted($this->bangla, $this->physics, $biology, $this->higherMath), $this->mySubjectIds($this->loginStudent($hmFourth)));
        // Biology and Physics are Science rows, Accounting is Business Studies.
        $this->assertSame($sorted($this->bangla, $this->accounting), $this->mySubjectIds($this->loginStudent($business)));
    }

    public function test_a_plain_optional_fourth_subject_is_shown_only_to_who_chose_it(): void
    {
        $agri = Subject::factory()->create(['name' => 'Agriculture']);
        $this->curriculum($this->class9, $agri, null, 'optional');
        $this->homework($this->section9, $agri);

        $chose = $this->loginStudent($this->enrol($this->section9, 'science', $agri, 1));
        $didNot = $this->loginStudent($this->enrol($this->section9, 'science', null, 2));

        $this->assertSame([$agri->id], $this->mySubjectIds($chose));
        $this->assertSame([], $this->mySubjectIds($didNot));
    }

    public function test_my_homework_has_the_resource_shape_and_filters(): void
    {
        $student = $this->loginStudent($this->enrol($this->section10, null, null, 1));
        $overdue = $this->homework($this->section10, $this->bangla, ['due_on' => '2026-10-14', 'assigned_on' => '2026-10-01']);
        $soon = $this->homework($this->section10, $this->bangla, ['due_on' => '2026-10-16']);
        $later = $this->homework($this->section10, $this->bangla, ['due_on' => '2026-11-30']);

        $ids = fn (string $q) => collect($this->as($student)->getJson('/api/my/homework'.$q)->assertOk()->json('data'))->pluck('id')->all();

        // Nearest due date first.
        $this->assertSame([$overdue->id, $soon->id, $later->id], $ids(''));
        $this->assertSame([$soon->id, $later->id], $ids('?due=upcoming'));
        $this->assertSame([$overdue->id], $ids('?due=past'));
        $this->assertSame([$soon->id], $ids('?from=2026-10-15&to=2026-10-21'));

        $this->as($student)->getJson('/api/my/homework')
            ->assertJsonStructure(['data' => [['id', 'title', 'details', 'due_on', 'is_overdue', 'has_attachment', 'attachment_url', 'subject', 'staff', 'section']]])
            ->assertJsonPath('data.0.is_overdue', true)
            ->assertJsonPath('data.1.is_overdue', false);
        $this->as($student)->getJson('/api/my/homework?due=x')->assertUnprocessable();
    }

    public function test_my_homework_attachment_is_a_signed_short_lived_link(): void
    {
        $student = $this->loginStudent($this->enrol($this->section10, null, null, 1));
        $id = $this->as($this->admin)->post('/api/homework', $this->payload($this->section10, $this->bangla, ['attachment' => $this->pdf('Exercises.pdf')]), ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');

        $url = $this->as($student)->getJson('/api/my/homework')->json('data.0.attachment_url');
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('expires=', $url);
        $this->assertStringNotContainsString('.pdf', parse_url($url, PHP_URL_QUERY));

        $this->get($url)->assertOk();
        // Staff see the authenticated endpoint instead.
        $this->assertStringEndsWith("/api/homework/{$id}/attachment", $this->as($this->admin)->getJson("/api/homework/{$id}")->json('data.attachment_url'));
    }

    public function test_a_guardian_sees_each_childs_homework_and_not_another_familys(): void
    {
        $firstChild = $this->enrol($this->section10, null, null, 1);
        $secondChild = $this->enrol($this->section9, 'business_studies', null, 2);
        $stranger = $this->enrol($this->section10, null, null, 3);
        $guardian = $this->loginGuardian($firstChild, $secondChild);
        $this->loginGuardian($stranger);

        $tenth = $this->homework($this->section10, $this->bangla);
        $ninth = $this->homework($this->section9, $this->accounting);
        $this->homework($this->section9, $this->physics);

        $ids = fn (int $student) => collect($this->as($guardian)->getJson("/api/my/homework?student={$student}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$tenth->id], $ids($firstChild->student_id));
        $this->assertSame([$ninth->id], $ids($secondChild->student_id));
        // Without ?student= the first child (the order of /api/my/children).
        $first = $this->as($guardian)->getJson('/api/my/children')->json('data.0.id');
        $this->assertSame($ids($first), collect($this->as($guardian)->getJson('/api/my/homework')->json('data'))->pluck('id')->all());

        $this->as($guardian)->getJson("/api/my/homework?student={$stranger->student_id}")->assertForbidden();
        $this->as($guardian)->getJson('/api/my/homework?student=999999')->assertForbidden();
    }

    public function test_my_homework_is_for_students_and_guardians_only(): void
    {
        $this->getJson('/api/my/homework')->assertUnauthorized();
        $this->as($this->teacher)->getJson('/api/my/homework')->assertForbidden();
        $this->as($this->admin)->getJson('/api/my/homework')->assertForbidden();
    }

    public function test_a_student_without_an_enrolment_gets_an_empty_list(): void
    {
        $user = $this->userWithRole('student');
        \App\Models\Student::factory()->create(['user_id' => $user->id]);

        $this->as($user)->getJson('/api/my/homework')->assertOk()->assertExactJson(['data' => []]);
    }

    // Delete guards

    public function test_a_subject_section_year_or_author_with_homework_cannot_be_deleted(): void
    {
        $teacher = $this->assignedTeacher($this->section10, $this->bangla);
        $member = Staff::where('user_id', $teacher->id)->first();
        $this->homework($this->section10, $this->bangla, ['staff_id' => $member->id]);
        // Nothing else references them.
        \App\Models\SubjectAssignment::query()->delete();
        \App\Models\Period::query()->delete();
        $this->curriculum($this->class10, $this->physics, null, 'compulsory');
        ClassSubject::where('subject_id', $this->bangla->id)->delete();

        $this->as($this->admin)->deleteJson("/api/subjects/{$this->bangla->id}")->assertConflict()->assertJsonPath('message', 'Subject has homework and cannot be deleted.');
        $this->as($this->admin)->deleteJson("/api/sections/{$this->section10->id}")->assertConflict()->assertJsonPath('message', 'Section has homework and cannot be deleted.');
        $this->as($this->admin)->deleteJson("/api/staff/{$member->id}")->assertConflict()->assertJsonPath('message', 'Staff member has assigned homework and cannot be deleted.');

        $other = \App\Models\AcademicYear::factory()->create(['year' => 2027]);
        $this->homework($this->section10, $this->bangla, ['academic_year_id' => $other->id]);
        $this->as($this->admin)->deleteJson("/api/academic-years/{$other->id}")->assertConflict()->assertJsonPath('message', 'Academic year has homework and cannot be deleted.');
    }
}
