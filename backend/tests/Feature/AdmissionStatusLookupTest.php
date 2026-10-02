<?php

namespace Tests\Feature;

use App\Models\AdmissionApplication;
use App\Services\AdmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsAdmissions;
use Tests\TestCase;

class AdmissionStatusLookupTest extends TestCase
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
            'test_at' => '2026-10-20 04:30:00', // 10:30 in Dhaka
            'test_venue' => 'Room 101',
            'test_score' => '72.50',
            'admin_note' => 'Strong candidate, call the father.',
            'father_mobile' => '01811111111',
            'guardian_mobile' => '01722222222',
            'birth_registration_number' => '20140123456789012',
        ]);
    }

    private function lookup(array $overrides = [])
    {
        return $this->post(route('admissions.status.show'), array_merge([
            'application_no' => 'ADM-2026-000007',
            'date_of_birth' => '2014-05-20',
        ], $overrides));
    }

    public function test_a_validation_failure_never_flashes_the_date_of_birth(): void
    {
        $this->from(route('admissions.status'))
            ->post(route('admissions.status.show'), ['application_no' => str_repeat('X', 40), 'date_of_birth' => '2014-05-20'])
            ->assertRedirect(route('admissions.status'))
            ->assertSessionHasErrors('application_no')
            ->assertSessionHasInput('application_no');

        $this->assertNull(session('_old_input.date_of_birth'));

        $this->postJson('/api/public/admission-status', ['application_no' => str_repeat('X', 40), 'date_of_birth' => '2014-05-20'])
            ->assertUnprocessable()->assertJsonValidationErrors('application_no');
    }

    public function test_the_right_number_and_date_of_birth_show_the_status_and_test_details(): void
    {
        $response = $this->lookup()->assertOk();

        $response->assertSee('ADM-2026-000007')
            ->assertSee('করিম উদ্দিন')
            ->assertSee('ষষ্ঠ শ্রেণি')
            ->assertSee('পরীক্ষা/সাক্ষাৎকারের সময় নির্ধারিত')
            ->assertSee('Room 101')
            ->assertSee('২০ অক্টোবর ২০২৬, পূর্বাহ্ন ১০:৩০');
        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
    }

    public function test_the_page_never_shows_private_fields(): void
    {
        $html = $this->lookup()->assertOk()->getContent();

        foreach (['Strong candidate', '72.50', '01811111111', '01722222222', '20140123456789012', 'Mirpur'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }

        $body = $this->postJson('/api/public/admission-status', ['application_no' => 'ADM-2026-000007', 'date_of_birth' => '2014-05-20'])
            ->assertOk()->json('data');
        $this->assertSame(['application_no', 'name_en', 'name_bn', 'class', 'round', 'status', 'status_label_bn', 'status_label_en', 'test_at', 'test_venue', 'submitted_at'], array_keys($body));
    }

    public function test_the_number_is_matched_without_regard_to_case_or_spaces(): void
    {
        $this->lookup(['application_no' => ' adm-2026-000007 '])->assertOk()->assertSee('করিম উদ্দিন');
    }

    public function test_every_miss_gets_the_same_answer(): void
    {
        $misses = [
            'unknown number' => ['application_no' => 'ADM-2026-999999'],
            'wrong date of birth' => ['date_of_birth' => '2014-05-21'],
            'both wrong' => ['application_no' => 'ADM-1999-000001', 'date_of_birth' => '2001-01-01'],
        ];

        $webRedirects = [];
        $apiMessages = [];
        foreach ($misses as $overrides) {
            $response = $this->lookup($overrides)->assertRedirect(route('admissions.status'))
                ->assertSessionHasErrors(['lookup' => \App\Exceptions\AdmissionApplicationNotFoundException::MESSAGE]);
            $response->assertSessionHasInput('application_no');
            $this->assertNull(session('_old_input.date_of_birth'));
            $webRedirects[] = $response->headers->get('Location');

            $api = $this->postJson('/api/public/admission-status', array_merge(['application_no' => 'ADM-2026-000007', 'date_of_birth' => '2014-05-20'], $overrides))->assertNotFound();
            $apiMessages[] = $api->json('message');
        }

        $this->assertCount(1, array_unique($webRedirects));
        $this->assertSame([\App\Exceptions\AdmissionApplicationNotFoundException::MESSAGE], array_unique($apiMessages));
    }

    public function test_the_form_and_the_answers_are_noindex_and_no_store(): void
    {
        $form = $this->get(route('admissions.status'))->assertOk();
        $result = $this->lookup()->assertOk();
        $miss = $this->lookup(['date_of_birth' => '2014-05-21']);

        foreach ([$form, $result, $miss] as $response) {
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        }

        foreach ([$form, $result] as $response) {
            $this->assertStringContainsString('noindex, nofollow', $response->getContent());
            $this->assertStringNotContainsString('rel="canonical"', $response->getContent());
            $this->assertSame(1, substr_count($response->getContent(), '<h1'));
        }
    }

    public function test_the_lookup_is_post_only(): void
    {
        $this->get(route('admissions.status', ['application_no' => 'ADM-2026-000007', 'date_of_birth' => '2014-05-20']))
            ->assertOk()->assertDontSee('করিম উদ্দিন');
    }

    public function test_the_lookup_validates_its_input(): void
    {
        $this->lookup(['application_no' => '', 'date_of_birth' => 'tomorrow'])->assertSessionHasErrors(['application_no', 'date_of_birth']);
        $this->postJson('/api/public/admission-status', [])->assertUnprocessable()->assertJsonValidationErrors(['application_no', 'date_of_birth']);
        $this->postJson('/api/public/admission-status', ['application_no' => ['x'], 'date_of_birth' => '2014-05-20'])->assertUnprocessable();
    }

    public function test_too_many_misses_block_the_ip_even_for_a_right_answer(): void
    {
        foreach (range(1, AdmissionService::MAX_STATUS_FAILURES) as $i) {
            RateLimiter::hit(AdmissionService::statusFailureKey('127.0.0.1'), 3600);
        }

        $this->lookup()->assertStatus(429)->assertHeader('Retry-After');
        $this->postJson('/api/public/admission-status', ['application_no' => 'ADM-2026-000007', 'date_of_birth' => '2014-05-20'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_misses_are_counted_and_hits_are_not(): void
    {
        $this->lookup()->assertOk();
        $this->assertSame(0, RateLimiter::attempts(AdmissionService::statusFailureKey('127.0.0.1')));

        $this->lookup(['date_of_birth' => '2014-05-21']);
        $this->assertSame(1, RateLimiter::attempts(AdmissionService::statusFailureKey('127.0.0.1')));
    }

    public function test_the_lookup_has_a_per_minute_throttle(): void
    {
        foreach (range(1, 10) as $i) {
            $this->lookup()->assertOk();
        }

        $this->lookup()->assertStatus(429);
    }

    public function test_the_public_api_matches_the_website(): void
    {
        $api = $this->postJson('/api/public/admission-status', ['application_no' => 'ADM-2026-000007', 'date_of_birth' => '2014-05-20'])
            ->assertOk()
            ->assertJsonPath('data.status', 'test_scheduled')
            ->assertJsonPath('data.test_venue', 'Room 101')
            ->assertJsonPath('data.test_at', '2026-10-20T04:30:00+00:00')
            ->assertJsonPath('data.class.number', 6);

        $html = $this->lookup()->getContent();
        $this->assertStringContainsString(e($api->json('data.name_bn')), $html);
        $this->assertStringContainsString(e($api->json('data.status_label_bn')), $html);
        $this->assertStringContainsString(e($api->json('data.test_venue')), $html);
    }

    public function test_the_public_rounds_api_matches_the_admissions_page(): void
    {
        $this->round->update(['instructions_bn' => '<p>আবেদনের নিয়ম</p>']);
        \App\Models\AdmissionRound::factory()->unpublished()->create(['academic_year_id' => $this->year->id, 'name_bn' => 'খসড়া চক্র']);
        \App\Models\AdmissionRound::factory()->closed()->create(['academic_year_id' => $this->year->id, 'name_bn' => 'বন্ধ চক্র']);

        $api = $this->getJson('/api/public/admission-rounds')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->round->id)
            ->assertJsonPath('data.0.is_open', true)
            ->assertJsonPath('data.0.opens_at', $this->round->opens_at->toDateString())
            ->assertJsonPath('data.0.classes.0.class.number', 6)
            ->assertJsonPath('data.0.classes.0.seats', 2)
            ->assertJsonPath('data.0.classes.1.requires_group', true)
            ->assertJsonPath('data.0.classes.1.seats', null)
            ->assertJsonPath('data.0.apply_url', route('admissions.apply', $this->round));

        $page = $this->get('/admissions')->assertOk();
        $page->assertSee('ভর্তি ২০২৬')->assertSee('আবেদনের নিয়ম', false)->assertSee(route('admissions.apply', $this->round), false);
        $page->assertDontSee('খসড়া চক্র')->assertDontSee('বন্ধ চক্র');
        $this->assertSame($api->json('data.0.name_bn'), 'ভর্তি ২০২৬');
    }

    public function test_the_public_rounds_api_is_empty_when_nothing_is_open(): void
    {
        $this->round->update(['is_published' => false]);

        $this->getJson('/api/public/admission-rounds')->assertOk()->assertExactJson(['data' => []]);
        $this->get('/admissions')->assertOk()->assertSee('কোনো ভর্তি চক্র খোলা নেই');
    }
}
