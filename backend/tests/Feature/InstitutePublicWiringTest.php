<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\PublicContentSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Public wiring for the institute settings module: the layout, contact page,
 * JSON-LD and /api/public/school all read InstituteSettingsService::profile(), with
 * config('seo.*') as the fallback for an empty settings table.
 */
class InstitutePublicWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PublicContentSeeder::class);
    }

    public function test_fallback_home_page_and_school_api_show_config_values_when_settings_are_empty(): void
    {
        $this->assertDatabaseCount('settings', 0);

        $this->get('/')->assertOk()->assertSee(config('seo.site_name'));

        $this->getJson('/api/public/school')
            ->assertOk()
            ->assertJsonPath('data.name', config('seo.site_name'))
            ->assertJsonPath('data.email', config('seo.organization')['email'])
            ->assertJsonPath('data.address.country', 'BD');
    }

    public function test_home_page_shows_uploaded_logo_and_name_once_saved(): void
    {
        Storage::fake('public', ['url' => config('filesystems.disks.public.url')]);

        $this->seed(RolePermissionSeeder::class);
        $admin = User::where('email', 'admin@sms.com')->firstOrFail();

        $logo = UploadedFile::fake()->image('logo.png', 100, 100);

        $this->actingAs($admin, 'sanctum')
            ->post('/api/settings/institute', [
                '_method' => 'PUT',
                'name_en' => 'Green View High School',
                'name_bn' => 'গ্রীন ভিউ উচ্চ বিদ্যালয়',
                'logo' => $logo,
            ])
            ->assertOk();

        $logoUrl = Storage::disk('public')->url(Setting::where('key', 'logo')->value('value'));

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Green View High School', $html);
        $this->assertStringContainsString('<img src="'.$logoUrl.'"', $html);
        $this->assertStringContainsString('alternateName', $this->jsonLdFromHtml($html));
        $this->assertStringContainsString('গ্রীন ভিউ উচ্চ বিদ্যালয়', $this->jsonLdFromHtml($html));
        $this->assertStringContainsString('"addressCountry":"BD"', $this->jsonLdFromHtml($html));
    }

    public function test_favicon_link_points_to_the_uploaded_favicon(): void
    {
        Storage::fake('public', ['url' => config('filesystems.disks.public.url')]);

        $this->seed(RolePermissionSeeder::class);
        $admin = User::where('email', 'admin@sms.com')->firstOrFail();

        $favicon = UploadedFile::fake()->create('favicon.png', 10, 'image/png');

        $this->actingAs($admin, 'sanctum')
            ->post('/api/settings/institute', ['_method' => 'PUT', 'favicon' => $favicon])
            ->assertOk();

        $faviconUrl = Storage::disk('public')->url(Setting::where('key', 'favicon')->value('value'));

        $this->get('/')->assertOk()->assertSee('<link rel="icon" href="'.$faviconUrl.'">', false);
    }

    public function test_contact_page_shows_upazila_district_and_a_maps_link_when_coordinates_are_set(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::where('email', 'admin@sms.com')->firstOrFail();

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/settings/institute', [
                'upazila' => 'Mohammadpur',
                'district' => 'Dhaka',
                'latitude' => 23.7639,
                'longitude' => 90.3489,
            ])
            ->assertOk();

        $html = $this->get('/contact')->assertOk()->getContent();

        $this->assertStringContainsString('Mohammadpur', $html);
        $this->assertStringContainsString('Dhaka', $html);
        $this->assertStringContainsString('google.com/maps?q=23.7639,90.3489', $html);
    }

    private function jsonLdFromHtml(string $html): string
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        return implode(' ', $matches[1] ?? []);
    }
}
