<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\AdmissionRound;
use App\Models\AdmissionRoundClass;
use App\Models\Student;
use App\Models\StudentEnrolment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsAdmissions;
use Tests\TestCase;

class AdmissionApplicationApiTest extends TestCase
{
    use BuildsAdmissions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAdmissions();
    }

    private function moveTo(AdmissionApplication $application, string $status, array $extra = [], $user = null)
    {
        return $this->as($user ?? $this->admin)->patchJson("/api/admission-applications/{$application->id}/status", ['status' => $status] + $extra);
    }

    private function convertPayload(array $overrides = []): array
    {
        return array_merge(['section_id' => $this->section6->id, 'password' => 'secret-pass-1', 'guardian_password' => 'guardian-pass-1'], $overrides);
    }

    public function test_list_filters_and_counts(): void
    {
        $a = $this->application(['name_en' => 'Alpha Student']);
        $this->application(['name_en' => 'Beta Student', 'status' => AdmissionApplication::STATUS_APPROVED, 'class_id' => $this->class9->id, 'group' => 'science', 'guardian_mobile' => '01999999999']);

        $this->as($this->office)->getJson('/api/admission-applications')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'application_no', 'status', 'class', 'round', 'files']], 'links', 'meta' => ['total']])
            ->assertJsonCount(2, 'data');
        $this->as($this->office)->getJson('/api/admission-applications?status=approved')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name_en', 'Beta Student');
        $this->as($this->office)->getJson("/api/admission-applications?class_id={$this->class6->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->as($this->office)->getJson("/api/admission-applications?round_id={$this->round->id}&search=Alpha")->assertJsonCount(1, 'data');
        $this->as($this->office)->getJson('/api/admission-applications?search=01999999999')->assertJsonCount(1, 'data');
        $this->as($this->office)->getJson('/api/admission-applications?search[]=x')->assertUnprocessable();
        $this->as($this->office)->getJson('/api/admission-applications?status=nope')->assertUnprocessable();

        $this->as($this->office)->getJson('/api/admission-applications/counts')->assertOk()
            ->assertJsonPath('data.submitted', 1)->assertJsonPath('data.approved', 1)->assertJsonPath('data.rejected', 0);
    }

    public function test_sensitive_fields_need_edit_students(): void
    {
        $application = $this->application(['birth_registration_number' => '20140123456789012', 'guardian_mobile' => '01712345678', 'admin_note' => 'note']);

        $teacher = $this->as($this->teacher)->getJson("/api/admission-applications/{$application->id}")->assertOk();
        $json = json_encode($teacher->json('data'));
        foreach (['20140123456789012', '01712345678', 'Dhaka', 'note'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertArrayNotHasKey('birth_registration_number', $teacher->json('data'));

        $this->as($this->teacher)->getJson('/api/admission-applications?search=01712345678')->assertJsonCount(0, 'data');
        $this->as($this->teacher)->getJson('/api/admission-applications')->assertJsonMissingPath('data.0.guardian.mobile');

        $this->as($this->office)->getJson("/api/admission-applications/{$application->id}")->assertOk()
            ->assertJsonPath('data.birth_registration_number', '20140123456789012')
            ->assertJsonPath('data.guardian.mobile', '01712345678')
            ->assertJsonPath('data.present_address', 'Dhaka')
            ->assertJsonPath('data.files.photo', true)
            ->assertJsonMissingPath('data.photo_path')
            ->assertJsonMissingPath('data.submitted_ip_hash');
        $this->as($this->admin)->getJson('/api/admission-applications/9999')->assertNotFound();
    }

    public function test_the_normal_path_through_the_statuses(): void
    {
        $application = $this->application();

        $this->moveTo($application, 'under_review')->assertOk()->assertJsonPath('data.status', 'under_review');
        $this->moveTo($application, 'test_scheduled', ['test_at' => '2026-10-20T10:30:00+06:00', 'test_venue' => 'Room 5'])
            ->assertOk()->assertJsonPath('data.test_at', '2026-10-20T04:30:00+00:00')->assertJsonPath('data.test_venue', 'Room 5');
        $this->moveTo($application, 'approved', ['test_score' => '78.5', 'admin_note' => 'Good'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.test_score', '78.50')->assertJsonPath('data.decided_by', $this->admin->id);

        $this->assertNotNull($application->fresh()->decided_at);
    }

    public function test_illegal_transitions_are_rejected(): void
    {
        $application = $this->application();

        $this->moveTo($application, 'approved')->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->moveTo($application, 'admitted')->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->moveTo($application, 'submitted')->assertUnprocessable();

        $this->moveTo($application, 'rejected')->assertOk();
        $this->moveTo($application, 'under_review')->assertUnprocessable()->assertJsonValidationErrors(['status']);
        $this->moveTo($application, 'approved')->assertUnprocessable();

        $waiting = $this->application(['status' => 'waitlisted']);
        $this->moveTo($waiting, 'under_review')->assertUnprocessable();
        $this->moveTo($waiting, 'approved')->assertOk();
    }

    public function test_scheduling_a_test_needs_a_time(): void
    {
        $application = $this->application(['status' => 'under_review']);

        $this->moveTo($application, 'test_scheduled')->assertUnprocessable()->assertJsonValidationErrors(['test_at']);
        $this->moveTo($application, 'test_scheduled', ['test_at' => 'soon'])->assertUnprocessable()->assertJsonValidationErrors(['test_at']);
        $this->moveTo($application, 'under_review', ['test_score' => -1])->assertUnprocessable()->assertJsonValidationErrors(['test_score']);
    }

    public function test_approving_beyond_the_seats_is_a_conflict(): void
    {
        // Class 6 has 2 seats: one approved, one admitted.
        $this->application(['status' => 'approved']);
        $this->application(['status' => 'admitted']);
        $third = $this->application(['status' => 'under_review']);
        $waitlisted = $this->application(['status' => 'waitlisted']);

        $this->moveTo($third, 'approved')->assertStatus(409);
        $this->moveTo($waitlisted, 'approved')->assertStatus(409);
        $this->moveTo($third, 'waitlisted')->assertOk();
        $this->assertSame('under_review', $third->fresh()->status === 'waitlisted' ? 'under_review' : 'x');

        // Another class without a limit is unaffected, and a rejected one frees nothing to count.
        $nine = $this->application(['class_id' => $this->class9->id, 'group' => 'science', 'status' => 'under_review']);
        $this->moveTo($nine, 'approved')->assertOk();
    }

    public function test_a_seat_frees_up_when_a_rejected_or_other_class_application_is_not_counted(): void
    {
        $this->application(['status' => 'approved']);
        $this->application(['status' => 'rejected']);
        $next = $this->application(['status' => 'under_review']);

        $this->moveTo($next, 'approved')->assertOk();
    }

    public function test_convert_creates_the_student_logins_and_enrolment_in_the_rounds_year(): void
    {
        $next = AcademicYear::factory()->create(['year' => 2027]);
        $round = AdmissionRound::factory()->create(['academic_year_id' => $next->id]);
        AdmissionRoundClass::create(['round_id' => $round->id, 'class_id' => $this->class6->id, 'seats' => null]);
        $application = $this->application([
            'round_id' => $round->id, 'status' => 'approved', 'name_en' => 'Karim Uddin', 'name_bn' => 'করিম উদ্দিন',
            'guardian_mobile' => '01712345678', 'guardian_name' => 'Abdul Uddin', 'birth_registration_number' => '20140123456789012',
            'father_name_en' => 'Abdul Uddin',
        ]);

        $response = $this->as($this->office)->postJson("/api/admission-applications/{$application->id}/convert", $this->convertPayload(['roll_number' => 7]))
            ->assertOk()->assertJsonPath('data.status', 'admitted');

        $student = Student::findOrFail($response->json('data.student_id'));
        $this->assertSame('Karim Uddin', $student->name_en);
        $this->assertSame('20140123456789012', $student->birth_registration_number);
        $this->assertSame('2014-05-20', $student->date_of_birth->toDateString());
        $this->assertSame('2027-01-01', $student->admission_date->toDateString());
        $this->assertNotNull($student->user_id);
        $this->assertSame('01712345678', $student->guardianUser->username);

        $enrolment = StudentEnrolment::where('student_id', $student->id)->firstOrFail();
        $this->assertSame($next->id, $enrolment->academic_year_id);
        $this->assertSame($this->section6->id, $enrolment->section_id);
        $this->assertSame(7, $enrolment->roll_number);
        $this->assertSame(0, StudentEnrolment::where('academic_year_id', $this->year->id)->count());

        $this->assertSame($student->id, $application->fresh()->student_id);
    }

    public function test_convert_copies_the_photo_to_the_student(): void
    {
        $application = $this->application(['status' => 'approved']);

        $studentId = $this->as($this->admin)->postJson("/api/admission-applications/{$application->id}/convert", $this->convertPayload())
            ->assertOk()->json('data.student_id');

        $student = Student::findOrFail($studentId);
        $this->assertNotNull($student->photo);
        $this->assertStringStartsWith('students/', $student->photo);
        Storage::disk('public')->assertExists($student->photo);
        Storage::disk('local')->assertExists($application->photo_path);
    }

    public function test_a_failure_after_the_student_is_created_removes_the_copied_photo(): void
    {
        $application = $this->application(['status' => 'approved']);
        $before = Storage::disk('public')->allFiles();

        AdmissionApplication::updating(fn () => throw new \RuntimeException('forced'));

        $this->withoutExceptionHandling();
        try {
            $this->as($this->admin)->postJson("/api/admission-applications/{$application->id}/convert", $this->convertPayload());
            $this->fail('Expected the forced failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('forced', $e->getMessage());
        }

        $this->assertSame($before, Storage::disk('public')->allFiles());
        $this->assertSame(0, Student::count());
    }

    public function test_siblings_share_the_guardian_login(): void
    {
        $first = $this->application(['status' => 'approved', 'guardian_mobile' => '01712345678']);
        $second = $this->application(['status' => 'approved', 'guardian_mobile' => '01712345678']);

        $a = $this->as($this->admin)->postJson("/api/admission-applications/{$first->id}/convert", $this->convertPayload())->assertOk()->json('data.student_id');
        $b = $this->as($this->admin)->postJson("/api/admission-applications/{$second->id}/convert", $this->convertPayload(['guardian_password' => null, 'roll_number' => 2]))->assertOk()->json('data.student_id');

        $this->assertSame(Student::find($a)->guardian_user_id, Student::find($b)->guardian_user_id);
    }

    public function test_convert_needs_an_approved_application(): void
    {
        foreach (['submitted', 'under_review', 'waitlisted', 'rejected', 'admitted'] as $status) {
            $application = $this->application(['status' => $status]);
            $this->as($this->admin)->postJson("/api/admission-applications/{$application->id}/convert", $this->convertPayload())->assertStatus(409);
        }

        $this->assertSame(0, Student::count());
    }

    public function test_convert_errors_keep_the_student_forms_keys_and_change_nothing(): void
    {
        $application = $this->application(['status' => 'approved']);
        $url = "/api/admission-applications/{$application->id}/convert";

        $this->as($this->admin)->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['section_id', 'password']);
        $this->as($this->admin)->postJson($url, $this->convertPayload(['guardian_password' => null]))
            ->assertUnprocessable()->assertJsonValidationErrors(['guardian_password']);
        $this->as($this->admin)->postJson($url, $this->convertPayload(['group' => 'science']))
            ->assertUnprocessable()->assertJsonValidationErrors(['enrolment.group']);
        $this->as($this->admin)->postJson($url, $this->convertPayload(['section_id' => $this->section9->id]))
            ->assertUnprocessable()->assertJsonValidationErrors(['section_id']);

        Student::factory()->create(['birth_registration_number' => $application->birth_registration_number]);
        $this->as($this->admin)->postJson($url, $this->convertPayload())->assertUnprocessable()->assertJsonValidationErrors(['birth_registration_number']);

        $fresh = $application->fresh();
        $this->assertSame('approved', $fresh->status);
        $this->assertNull($fresh->student_id);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_convert_a_class_nine_application_uses_its_group(): void
    {
        $application = $this->application(['status' => 'approved', 'class_id' => $this->class9->id, 'group' => 'science']);

        $id = $this->as($this->admin)->postJson("/api/admission-applications/{$application->id}/convert", $this->convertPayload(['section_id' => $this->section9->id]))
            ->assertOk()->json('data.student_id');

        $this->assertSame('science', StudentEnrolment::where('student_id', $id)->firstOrFail()->group);
    }

    public function test_only_staff_with_edit_students_download_files(): void
    {
        $application = $this->application();
        $url = "/api/admission-applications/{$application->id}/files/photo";

        $this->getJson($url)->assertUnauthorized();
        $this->as($this->teacher)->getJson($url)->assertForbidden();
        $this->as($this->userWithRole('parent'))->getJson($url)->assertForbidden();

        $response = $this->as($this->office)->get($url)->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('image/', $response->headers->get('Content-Type'));
        $this->as($this->admin)->get($url)->assertOk();
    }

    public function test_pdf_documents_download_as_attachments(): void
    {
        $application = $this->application();
        Storage::disk('local')->put('admissions/doc.pdf', '%PDF-1.4 test');
        $application->update(['birth_certificate_path' => 'admissions/doc.pdf']);

        $response = $this->as($this->admin)->get("/api/admission-applications/{$application->id}/files/birth_certificate")->assertOk();
        $this->assertStringStartsWith('attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));

        $photo = $this->as($this->admin)->get("/api/admission-applications/{$application->id}/files/photo")->assertOk();
        $this->assertStringStartsWith('inline', $photo->headers->get('Content-Disposition'));
    }

    public function test_missing_files_and_unknown_kinds_are_404(): void
    {
        $application = $this->application();

        $this->as($this->admin)->getJson("/api/admission-applications/{$application->id}/files/birth_certificate")->assertNotFound();
        $this->as($this->admin)->getJson("/api/admission-applications/{$application->id}/files/secrets")->assertNotFound();
        Storage::disk('local')->delete($application->photo_path);
        $this->as($this->admin)->getJson("/api/admission-applications/{$application->id}/files/photo")->assertNotFound();
        $this->as($this->admin)->getJson('/api/admission-applications/9999/files/photo')->assertNotFound();
    }

    public function test_review_actions_are_forbidden_for_a_teacher(): void
    {
        $application = $this->application(['status' => 'approved']);

        $this->patchJson("/api/admission-applications/{$application->id}/status", ['status' => 'rejected'])->assertUnauthorized();
        $this->moveTo($application, 'rejected', [], $this->teacher)->assertForbidden();
        $this->as($this->teacher)->postJson("/api/admission-applications/{$application->id}/convert", $this->convertPayload())->assertForbidden();
        $this->as($this->userWithRole('student'))->getJson('/api/admission-applications')->assertForbidden();
    }
}
