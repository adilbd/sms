<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\AdmissionApplication;
use App\Models\AdmissionRound;
use App\Models\AdmissionRoundClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsAdmissions;
use Tests\TestCase;

class AdmissionApplyTest extends TestCase
{
    use BuildsAdmissions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAdmissions();
    }

    public function test_a_submission_with_only_a_photo_succeeds_and_stores_files_privately(): void
    {
        $this->submitApplication()->assertRedirect(route('admissions.submitted'));

        $application = AdmissionApplication::firstOrFail();
        $this->assertSame('ADM-2026-000001', $application->application_no);
        $this->assertSame(AdmissionApplication::STATUS_SUBMITTED, $application->status);
        $this->assertSame($this->class6->id, $application->class_id);
        $this->assertNull($application->birth_certificate_path);
        $this->assertNull($application->previous_school_doc_path);
        $this->assertSame(sha1('127.0.0.1'), $application->submitted_ip_hash);

        Storage::disk('local')->assertExists($application->photo_path);
        $this->assertStringStartsWith('admissions/', $application->photo_path);
        $this->assertSame([], Storage::disk('public')->allFiles());

        // A public request for the stored path finds nothing.
        $this->get('/storage/'.$application->photo_path)->assertNotFound();
    }

    public function test_optional_documents_are_stored_privately_too(): void
    {
        $this->submitApplication([
            'birth_certificate' => UploadedFile::fake()->create('certificate.pdf', 300, 'application/pdf'),
            'previous_school_doc' => UploadedFile::fake()->image('tc.png')->size(400),
        ])->assertRedirect(route('admissions.submitted'));

        $application = AdmissionApplication::firstOrFail();
        Storage::disk('local')->assertExists($application->birth_certificate_path);
        Storage::disk('local')->assertExists($application->previous_school_doc_path);
        $this->assertStringEndsWith('.pdf', $application->birth_certificate_path);
    }

    public function test_the_confirmation_shows_the_number_and_is_private(): void
    {
        $response = $this->followingRedirects()->submitApplication();

        $response->assertOk()->assertSee('ADM-2026-000001')->assertSee('করিম উদ্দিন');
        $html = $response->getContent();
        $this->assertStringContainsString('noindex, nofollow', $html);
        $this->assertSame(1, substr_count($html, '<h1'));

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));

        // One-shot: a refresh no longer shows the number.
        $this->get(route('admissions.submitted'))->assertRedirect(route('admissions'));
    }

    public function test_the_confirmation_without_a_submission_goes_back_to_admissions(): void
    {
        $this->get(route('admissions.submitted'))->assertRedirect(route('admissions'));
    }

    public function test_application_numbers_are_sequential_within_the_year(): void
    {
        foreach (range(1, 3) as $i) {
            $this->submitApplication()->assertRedirect(route('admissions.submitted'));
        }

        $this->assertSame(
            ['ADM-2026-000001', 'ADM-2026-000002', 'ADM-2026-000003'],
            AdmissionApplication::orderBy('id')->pluck('application_no')->all(),
        );
    }

    public function test_the_number_carries_the_rounds_academic_year(): void
    {
        $next = AcademicYear::factory()->create(['year' => 2027]);
        $round = AdmissionRound::factory()->create(['academic_year_id' => $next->id]);
        AdmissionRoundClass::create(['round_id' => $round->id, 'class_id' => $this->class6->id, 'seats' => null]);

        $this->submitApplication([], $round);
        $this->submitApplication();

        $this->assertSame(['ADM-2027-000001', 'ADM-2026-000001'], AdmissionApplication::orderBy('id')->pluck('application_no')->all());
    }

    public function test_a_missing_photo_is_rejected(): void
    {
        $payload = $this->applicationPayload();
        unset($payload['photo']);

        $this->post(route('admissions.apply.store', $this->round), $payload)->assertSessionHasErrors('photo');
        $this->assertSame(0, AdmissionApplication::count());
    }

    public function test_file_types_and_sizes_are_enforced(): void
    {
        $this->submitApplication(['photo' => UploadedFile::fake()->create('photo.pdf', 100, 'application/pdf')])->assertSessionHasErrors('photo');
        $this->submitApplication(['photo' => UploadedFile::fake()->image('photo.jpg')->size(2049)])->assertSessionHasErrors('photo');
        $this->submitApplication(['photo' => UploadedFile::fake()->image('photo.jpg')->size(2048)])->assertSessionHasNoErrors();
        $this->submitApplication(['birth_certificate' => UploadedFile::fake()->create('c.pdf', 5121, 'application/pdf')])->assertSessionHasErrors('birth_certificate');
        $this->submitApplication(['birth_certificate' => UploadedFile::fake()->create('c.docx', 10, 'application/msword')])->assertSessionHasErrors('birth_certificate');
        $this->submitApplication(['previous_school_doc' => UploadedFile::fake()->create('t.exe', 10, 'application/octet-stream')])->assertSessionHasErrors('previous_school_doc');

        $this->assertSame(1, AdmissionApplication::count());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_the_same_birth_registration_number_cannot_apply_twice_in_a_round(): void
    {
        $this->submitApplication(['birth_registration_number' => '20140123456789012'])->assertRedirect(route('admissions.submitted'));
        $files = Storage::disk('local')->allFiles();

        $response = $this->submitApplication(['birth_registration_number' => '20140123456789012']);

        $response->assertSessionHasErrors(['birth_registration_number' => \App\Services\AdmissionService::DUPLICATE_MESSAGE]);
        $this->assertSame(1, AdmissionApplication::count());
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public function test_the_same_child_may_apply_in_another_round(): void
    {
        $other = AdmissionRound::factory()->create(['academic_year_id' => $this->year->id]);
        AdmissionRoundClass::create(['round_id' => $other->id, 'class_id' => $this->class6->id, 'seats' => null]);

        $this->submitApplication(['birth_registration_number' => '20140123456789012'])->assertSessionHasNoErrors();
        $this->submitApplication(['birth_registration_number' => '20140123456789012'], $other)->assertSessionHasNoErrors();

        $this->assertSame(2, AdmissionApplication::count());
    }

    public function test_only_open_published_rounds_accept_applications(): void
    {
        $closed = AdmissionRound::factory()->closed()->create(['academic_year_id' => $this->year->id]);
        $draft = AdmissionRound::factory()->unpublished()->create(['academic_year_id' => $this->year->id]);
        $upcoming = AdmissionRound::factory()->upcoming()->create(['academic_year_id' => $this->year->id]);

        foreach ([$closed, $draft, $upcoming] as $round) {
            AdmissionRoundClass::create(['round_id' => $round->id, 'class_id' => $this->class6->id, 'seats' => null]);
            $this->get(route('admissions.apply', $round))->assertNotFound();
            $this->submitApplication([], $round)->assertNotFound();
        }

        $this->get('/admissions/apply/99999')->assertNotFound();
        $this->get('/admissions/apply/1abc')->assertNotFound();
        $this->assertSame(0, AdmissionApplication::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_round_closes_after_its_last_day(): void
    {
        $this->round->update(['closes_at' => '2026-10-15']);
        $this->get(route('admissions.apply', $this->round))->assertOk();

        $this->travelTo('2026-10-15 18:30:00'); // 00:30 on the 16th in Dhaka
        $this->get(route('admissions.apply', $this->round))->assertNotFound();
    }

    public function test_a_class_must_be_offered_in_the_round(): void
    {
        $this->submitApplication(['class_id' => $this->class10->id])->assertSessionHasErrors('class_id');
        $this->assertSame(0, AdmissionApplication::count());
    }

    public function test_a_group_is_required_from_class_nine_and_forbidden_below(): void
    {
        $this->submitApplication(['class_id' => $this->class9->id])->assertSessionHasErrors('group');
        $this->submitApplication(['group' => 'science'])->assertSessionHasErrors('group');
        $this->submitApplication(['class_id' => $this->class9->id, 'group' => 'astrology'])->assertSessionHasErrors('group');
        $this->assertSame(0, AdmissionApplication::count());

        $this->submitApplication(['class_id' => $this->class9->id, 'group' => 'science'])->assertRedirect(route('admissions.submitted'));
        $this->assertSame('science', AdmissionApplication::firstOrFail()->group);
    }

    public function test_the_fields_are_validated(): void
    {
        $this->submitApplication(['birth_registration_number' => '1234'])->assertSessionHasErrors('birth_registration_number');
        $this->submitApplication(['name_en' => '', 'name_bn' => ''])->assertSessionHasErrors(['name_en', 'name_bn']);
        $this->submitApplication(['guardian_mobile' => '12345'])->assertSessionHasErrors('guardian_mobile');
        $this->submitApplication(['date_of_birth' => '2999-01-01'])->assertSessionHasErrors('date_of_birth');
        $this->submitApplication(['gender' => 'x'])->assertSessionHasErrors('gender');
        $this->submitApplication(['present_address' => ''])->assertSessionHasErrors('present_address');
        $this->submitApplication(['guardian_email' => 'not-an-email'])->assertSessionHasErrors('guardian_email');
        $this->assertSame(0, AdmissionApplication::count());
    }

    public function test_one_name_is_enough_and_mobiles_are_normalized(): void
    {
        $this->submitApplication(['name_en' => null, 'guardian_mobile' => '+8801712345678', 'father_mobile' => '01812-345678'])
            ->assertRedirect(route('admissions.submitted'));

        $application = AdmissionApplication::firstOrFail();
        $this->assertSame('01712345678', $application->guardian_mobile);
        $this->assertSame('01812345678', $application->father_mobile);
        $this->assertNull($application->name_en);
    }

    public function test_a_filled_honeypot_is_rejected_without_storing_anything(): void
    {
        $this->submitApplication(['website' => 'http://spam.example'])->assertSessionHasErrors('website');

        $this->assertSame(0, AdmissionApplication::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_submitting_too_often_is_throttled(): void
    {
        foreach (range(1, 10) as $i) {
            $this->submitApplication()->assertRedirect(route('admissions.submitted'));
        }

        $this->submitApplication()->assertStatus(429);
        $this->assertSame(10, AdmissionApplication::count());
    }

    public function test_the_apply_form_shows_the_rounds_classes_and_is_indexable(): void
    {
        $response = $this->get(route('admissions.apply', $this->round))->assertOk();
        $html = $response->getContent();

        $response->assertSee('ষষ্ঠ শ্রেণি')->assertSee('name="website"', false)->assertSee('enctype="multipart/form-data"', false);
        $this->assertStringContainsString('index, follow', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }
}
