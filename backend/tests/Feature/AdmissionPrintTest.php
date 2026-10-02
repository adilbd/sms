<?php

namespace Tests\Feature;

use App\Models\AdmissionApplication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsAdmissions;
use Tests\TestCase;

class AdmissionPrintTest extends TestCase
{
    use BuildsAdmissions, RefreshDatabase;

    private AdmissionApplication $application;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAdmissions();

        $this->application = $this->application([
            'application_no' => 'ADM-2026-000007',
            'name_en' => 'Karim Uddin',
            'name_bn' => 'করিম উদ্দিন',
            'date_of_birth' => '2014-05-20',
            'status' => AdmissionApplication::STATUS_TEST_SCHEDULED,
            'test_at' => '2026-10-20 04:30:00',
            'test_venue' => 'Room 101',
            'test_score' => '72.50',
            'admin_note' => 'Strong candidate, call the father.',
            'birth_registration_number' => '20140123456780123',
            'guardian_mobile' => '01722222222',
        ]);
    }

    private function assertPrivateHeaders($response): void
    {
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', $response->headers->get('X-Robots-Tag'));
    }

    private function assertCopyIsSafe($response): void
    {
        $response->assertSee('ADM-2026-000007')
            ->assertSee('করিম উদ্দিন')
            ->assertSee('ষষ্ঠ শ্রেণি')
            ->assertSee('*************0123')
            ->assertDontSee('20140123456780123')
            ->assertDontSee('Strong candidate')
            ->assertDontSee('72.50')
            ->assertSee('অভিভাবকের স্বাক্ষর', false);
    }

    public function test_the_confirmation_page_contains_the_copy_and_a_signed_photo_url(): void
    {
        $response = $this->withSession(['admission.submitted' => $this->application->id])
            ->get(route('admissions.submitted'));

        $response->assertOk();
        $this->assertCopyIsSafe($response);
        $response->assertSee('প্রিন্ট করুন (Print)');
        $this->assertMatchesRegularExpression(
            '#<img src="[^"]*/admissions/copy-photo/'.$this->application->id.'\?expires=\d+&amp;signature=[a-f0-9]+"#',
            $response->getContent(),
        );
        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
        $this->assertPrivateHeaders($response);
        $response->assertSee('noindex', false);
    }

    public function test_the_copy_shows_a_scheduled_test_and_the_confirmation_stays_one_shot(): void
    {
        $this->withSession(['admission.submitted' => $this->application->id])
            ->get(route('admissions.submitted'))
            ->assertSee('Room 101');

        $this->get(route('admissions.submitted'))->assertRedirect(route('admissions'));
    }

    public function test_a_successful_status_lookup_renders_the_copy(): void
    {
        $response = $this->post(route('admissions.status.show'), [
            'application_no' => 'ADM-2026-000007',
            'date_of_birth' => '2014-05-20',
        ]);

        $response->assertOk();
        $this->assertCopyIsSafe($response);
        $response->assertSee('Room 101')
            ->assertSee('আবেদনের কপি প্রিন্ট (Print application copy)')
            ->assertSee('/admissions/copy-photo/'.$this->application->id.'?expires=', false);
        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
        $this->assertPrivateHeaders($response);
    }

    public function test_a_status_lookup_miss_renders_no_copy(): void
    {
        $miss = $this->from(route('admissions.status'))->post(route('admissions.status.show'), [
            'application_no' => 'ADM-2026-000007',
            'date_of_birth' => '2014-05-21',
        ]);

        $unknown = $this->from(route('admissions.status'))->post(route('admissions.status.show'), [
            'application_no' => 'ADM-2026-999999',
            'date_of_birth' => '2014-05-20',
        ]);

        $this->assertSame($unknown->getStatusCode(), $miss->getStatusCode());
        $this->assertSame($unknown->getContent(), $miss->getContent());
        $miss->assertDontSee('admission-copy', false)->assertDontSee('copy-photo', false);
    }

    private function signedPhotoUrl(?int $minutes = 10): string
    {
        return URL::temporarySignedRoute('admissions.copy-photo', now()->addMinutes($minutes), ['application' => $this->application->id]);
    }

    public function test_a_valid_signature_serves_the_photo_with_private_headers(): void
    {
        $response = $this->get($this->signedPhotoUrl());

        $response->assertOk();
        $this->assertStringStartsWith('image/', $response->headers->get('Content-Type'));
        $this->assertPrivateHeaders($response);
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_a_missing_or_tampered_signature_is_forbidden(): void
    {
        $this->get(route('admissions.copy-photo', $this->application))->assertForbidden();
        $this->get($this->signedPhotoUrl().'0')->assertForbidden();

        $other = $this->application();
        $this->get(str_replace('/copy-photo/'.$this->application->id, '/copy-photo/'.$other->id, $this->signedPhotoUrl()))->assertForbidden();
    }

    public function test_an_expired_link_is_forbidden(): void
    {
        $url = $this->signedPhotoUrl();

        $this->travel(11)->minutes();

        $this->get($url)->assertForbidden();
    }

    public function test_the_route_serves_only_the_photo(): void
    {
        $url = $this->signedPhotoUrl();

        $this->get(str_replace('/copy-photo/'.$this->application->id, '/copy-photo/'.$this->application->id.'/birth_certificate', $url))
            ->assertNotFound();
    }

    public function test_a_missing_photo_file_is_not_found(): void
    {
        Storage::disk('local')->delete($this->application->photo_path);

        $this->get($this->signedPhotoUrl())->assertNotFound();
    }

    public function test_the_admin_list_returns_every_filtered_row_across_pages_and_bounds_per_page(): void
    {
        foreach (range(1, 5) as $i) {
            $this->application(['status' => AdmissionApplication::STATUS_APPROVED]);
        }

        Sanctum::actingAs($this->office);

        $seen = [];
        $page = 1;
        do {
            $response = $this->getJson('/api/admission-applications?status=approved&per_page=2&page='.$page)->assertOk();
            $seen = array_merge($seen, array_column($response->json('data'), 'id'));
            $last = $response->json('meta.last_page');
        } while (++$page <= $last);

        $this->assertCount(5, $seen);
        $this->assertCount(5, array_unique($seen));

        $this->getJson('/api/admission-applications?per_page=100000')->assertOk()->assertJsonPath('meta.per_page', 100);
        $this->getJson('/api/admission-applications?per_page=0')->assertOk()->assertJsonPath('meta.per_page', 1);
    }
}
