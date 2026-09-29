<?php

namespace Tests\Feature;

use App\Models\Shift;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPublicSeoTest extends TestCase
{
    use RefreshDatabase;

    public static function indexPages(): array
    {
        return [
            'head' => ['/administration/head'],
            'assistant head' => ['/administration/assistant-head'],
            'teachers' => ['/administration/teachers'],
            'staff' => ['/administration/staff'],
            'ex heads' => ['/administration/ex-heads'],
            'ex teachers' => ['/administration/ex-teachers'],
            'ex staff' => ['/administration/ex-staff'],
        ];
    }

    /** @dataProvider indexPages */
    public function test_index_page_has_full_seo_head_and_one_h1(string $uri): void
    {
        Shift::factory()->create();

        $html = $this->get($uri)->assertOk()->getContent();

        $this->assertSeoHead($html, url($uri));
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_head_page_with_no_head_shows_empty_state(): void
    {
        $html = $this->get('/administration/head')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_profile_page_has_full_seo_head_person_schema_and_one_h1(): void
    {
        $shift = Shift::factory()->create();
        $staff = Staff::factory()->create(['name_en' => 'Md. Karim', 'name_bn' => 'মো. করিম']);
        $staff->shifts()->attach($shift);

        $html = $this->get("/administration/staff/{$staff->id}")->assertOk()->getContent();

        $this->assertSeoHead($html, url("/administration/staff/{$staff->id}"));
        $this->assertSame(1, substr_count($html, '<h1'));
        $types = $this->jsonLdTypes($html);
        $this->assertContains('Person', $types);
        $this->assertContains('BreadcrumbList', $types);
    }

    public function test_unpublished_unknown_and_soft_deleted_profiles_return_noindex_404(): void
    {
        $unpublished = Staff::factory()->unpublished()->create();
        $deleted = Staff::factory()->create();
        $deleted->delete();

        $this->get("/administration/staff/{$unpublished->id}")->assertNotFound()->assertSee('noindex, nofollow', false);
        $this->get("/administration/staff/{$deleted->id}")->assertNotFound()->assertSee('noindex, nofollow', false);
        $this->get('/administration/staff/999999')->assertNotFound()->assertSee('noindex, nofollow', false);
    }

    public function test_unknown_shift_slug_returns_404(): void
    {
        $this->get('/administration/head?shift=nope')->assertNotFound();
    }

    /**
     * Regression test: a malformed query string (?shift[]=x) makes $request->query
     * ('shift') an array, which used to reach StaffService::resolveActiveShift()
     * (type-hinted string) uncaught, a 500 instead of a 404.
     */
    public function test_array_shift_query_parameter_returns_404_not_a_500(): void
    {
        $this->get('/administration/teachers?shift[]=x')->assertNotFound();
        $this->get('/administration/head?shift[]=x&shift[]=y')->assertNotFound();
    }

    /**
     * These list pages are never paginated (small, fixed lists), so ?page=N other than
     * 1 must not render the same content again at a second, indexable URL.
     */
    public function test_a_page_query_other_than_one_returns_404(): void
    {
        Shift::factory()->create();

        $this->get('/administration/teachers?page=1')->assertOk();
        $this->get('/administration/teachers?page=2')->assertNotFound();
        $this->get('/administration/teachers?page=9')->assertNotFound();
        $this->get('/administration/head?page=2')->assertNotFound();
    }

    public function test_former_staff_appear_only_on_ex_pages(): void
    {
        $active = Staff::factory()->create(['name_en' => 'Active Teacher', 'position' => Staff::POSITION_TEACHER]);
        $former = Staff::factory()->former()->create(['name_en' => 'Former Teacher', 'position' => Staff::POSITION_TEACHER]);
        Shift::factory()->create()->staff()->attach([$active->id, $former->id]);

        $current = $this->get('/administration/teachers')->assertOk()->getContent();
        $this->assertStringContainsString('Active Teacher', $current);
        $this->assertStringNotContainsString('Former Teacher', $current);

        $ex = $this->get('/administration/ex-teachers')->assertOk()->getContent();
        $this->assertStringContainsString('Former Teacher', $ex);
        $this->assertStringNotContainsString('Active Teacher', $ex);
    }

    public function test_profile_never_leaks_private_fields_and_mobile_needs_show_contact(): void
    {
        $shift = Shift::factory()->create();
        $staff = Staff::factory()->create([
            'nid' => '1234567890123',
            'date_of_birth' => '1980-01-01',
            'present_address' => 'Secret Village Road',
            'mpo_index' => 'MPO-999',
            'mobile' => '01711111111',
            'email' => 'private@example.com',
            'show_contact' => false,
        ]);
        $staff->shifts()->attach($shift);

        $html = $this->get("/administration/staff/{$staff->id}")->assertOk()->getContent();

        foreach (['1234567890123', '1980-01-01', 'Secret Village Road', 'MPO-999', '01711111111', 'private@example.com'] as $secret) {
            $this->assertStringNotContainsString($secret, $html);
        }

        $json = $this->getJson("/api/public/staff/{$staff->id}")->assertOk();
        foreach (['nid', 'date_of_birth', 'present_address', 'permanent_address', 'mpo_index'] as $field) {
            $json->assertJsonMissingPath("data.{$field}");
        }
        $this->assertNull($json->json('data.mobile'));
        $this->assertNull($json->json('data.email'));

        $staff->update(['show_contact' => true]);
        $htmlWithContact = $this->get("/administration/staff/{$staff->id}")->assertOk()->getContent();
        $this->assertStringContainsString('01711111111', $htmlWithContact);

        $jsonWithContact = $this->getJson("/api/public/staff/{$staff->id}")->assertOk();
        $this->assertSame('01711111111', $jsonWithContact->json('data.mobile'));
    }

    public function test_shift_pills_only_appear_with_more_than_one_active_shift(): void
    {
        $shift = Shift::factory()->create(['slug' => 'morning', 'name_en' => 'Morning']);
        $teacher = Staff::factory()->create(['position' => Staff::POSITION_TEACHER]);
        $teacher->shifts()->attach($shift);

        $html = $this->get('/administration/teachers')->assertOk()->getContent();
        $this->assertStringNotContainsString('Filter by shift', $html);

        $secondShift = Shift::factory()->create(['slug' => 'day', 'name_en' => 'Day']);
        $secondTeacher = Staff::factory()->create(['position' => Staff::POSITION_TEACHER]);
        $secondTeacher->shifts()->attach($secondShift);

        $htmlWithPills = $this->get('/administration/teachers')->assertOk()->getContent();
        $this->assertStringContainsString('Filter by shift', $htmlWithPills);

        $filtered = $this->get('/administration/teachers?shift=day')->assertOk()->getContent();
        $this->assertStringNotContainsString($teacher->name(), $filtered);
    }

    public function test_bangla_only_name_renders_correctly(): void
    {
        $shift = Shift::factory()->create();
        $staff = Staff::factory()->create(['name_en' => null, 'name_bn' => 'রহিম উদ্দিন']);
        $staff->shifts()->attach($shift);

        $html = $this->get("/administration/staff/{$staff->id}")->assertOk()->getContent();

        $this->assertStringContainsString('রহিম উদ্দিন', $html);
    }

    public function test_api_public_staff_list_and_show(): void
    {
        $shift = Shift::factory()->create();
        $teacher = Staff::factory()->create(['position' => Staff::POSITION_TEACHER]);
        $teacher->shifts()->attach($shift);
        Staff::factory()->former()->create(['position' => Staff::POSITION_TEACHER]);

        $this->getJson('/api/public/staff?position=teacher&former=0')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/public/staff?position=teacher&former=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/public/staff?position=teacher')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /**
     * Regression test: an unvalidated ?shift[]=x used to reach StaffService::
     * resolveActiveShift() (type-hinted string) as an array, a 500 instead of a 422.
     * An invalid position/former is rejected the same way.
     */
    public function test_public_staff_list_validates_its_filters(): void
    {
        $this->getJson('/api/public/staff?shift[]=x')->assertUnprocessable()->assertJsonValidationErrors('shift');
        $this->getJson('/api/public/staff?position[]=teacher')->assertUnprocessable()->assertJsonValidationErrors('position');
        $this->getJson('/api/public/staff?position=nope')->assertUnprocessable()->assertJsonValidationErrors('position');
        $this->getJson('/api/public/staff?former=maybe')->assertUnprocessable()->assertJsonValidationErrors('former');
    }

    private function assertSeoHead(string $html, string $canonical): void
    {
        $this->assertMatchesRegularExpression('/<title>[^<]+<\/title>/', $html);
        $this->assertMatchesRegularExpression('/<meta name="description" content="[^"]{10,160}">/u', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.$canonical.'">', $html);
        foreach (['og:title', 'og:description', 'og:url', 'og:type', 'twitter:card'] as $tag) {
            $this->assertStringContainsString($tag, $html, "Missing {$tag}");
        }
        $this->assertNotEmpty($this->jsonLd($html), 'Missing JSON-LD');
    }

    private function jsonLd(string $html): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $m);

        return array_map(function ($json) {
            $decoded = json_decode($json, true);
            $this->assertIsArray($decoded, 'Invalid JSON-LD: '.$json);

            return $decoded;
        }, $m[1]);
    }

    private function jsonLdTypes(string $html): array
    {
        return array_column($this->jsonLd($html), '@type');
    }
}
