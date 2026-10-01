<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstituteSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::where('email', 'admin@sms.com')->firstOrFail();

        // Storage::fake() drops the disk's "url" config unless it's passed back in, so
        // preserve it here to test the same absolute-URL behavior as the real public disk.
        Storage::fake('public', ['url' => config('filesystems.disks.public.url')]);
    }

    public function test_guest_is_unauthenticated_on_both_routes(): void
    {
        $this->getJson('/api/settings/institute')->assertUnauthorized();
        $this->putJson('/api/settings/institute', [])->assertUnauthorized();
    }

    public function test_role_without_view_settings_is_forbidden_on_show(): void
    {
        foreach (['teacher', 'student'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user, 'sanctum')->getJson('/api/settings/institute')->assertForbidden();
        }
    }

    public function test_role_without_edit_settings_is_forbidden_on_update(): void
    {
        foreach (['teacher', 'student'] as $role) {
            $user = User::factory()->create();
            $user->assignRole($role);

            $this->actingAs($user, 'sanctum')->putJson('/api/settings/institute', ['name_en' => 'X'])->assertForbidden();
        }
    }

    public function test_show_returns_every_registry_key_with_nulls_when_unset(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/settings/institute')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'name_en', 'name_bn', 'short_name', 'motto', 'established_year', 'eiin',
                'institute_code', 'mpo_code', 'education_board', 'logo_url', 'favicon_url',
                'phone', 'telephone', 'email', 'office_hours',
                'village', 'ward', 'union', 'post_office', 'post_code', 'upazila', 'district', 'division',
                'latitude', 'longitude', 'facebook_url', 'youtube_url',
            ]])
            ->assertJsonPath('data.name_en', null)
            ->assertJsonPath('data.logo_url', null)
            ->assertJsonPath('data.favicon_url', null);
    }

    public function test_update_saves_sent_fields_and_a_follow_up_get_returns_them(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/settings/institute', [
                'name_en' => 'Green View High School',
                'name_bn' => 'গ্রীন ভিউ উচ্চ বিদ্যালয়',
                'eiin' => '123456',
                'division' => 'dhaka',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Institute settings saved')
            ->assertJsonPath('data.name_en', 'Green View High School')
            ->assertJsonPath('data.name_bn', 'গ্রীন ভিউ উচ্চ বিদ্যালয়')
            ->assertJsonPath('data.eiin', '123456')
            ->assertJsonPath('data.division', 'dhaka');

        $this->assertDatabaseHas('settings', ['key' => 'eiin', 'value' => '123456']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/settings/institute')
            ->assertOk()
            ->assertJsonPath('data.name_en', 'Green View High School')
            ->assertJsonPath('data.eiin', '123456');
    }

    public function test_partial_update_leaves_other_keys_unchanged(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/settings/institute', ['name_en' => 'Original Name'])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/settings/institute', ['phone' => '+880123456789'])
            ->assertOk()
            ->assertJsonPath('data.phone', '+880123456789')
            ->assertJsonPath('data.name_en', 'Original Name');
    }

    public function test_unknown_key_is_ignored(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/settings/institute', ['foo' => 'bar', 'name_en' => 'A School'])
            ->assertOk();

        $this->assertDatabaseMissing('settings', ['key' => 'foo']);
    }

    public static function invalidPayloads(): array
    {
        return [
            'bad email' => [['email' => 'not-an-email'], 'email'],
            'latitude too high' => [['latitude' => 91], 'latitude'],
            'longitude too low' => [['longitude' => -181], 'longitude'],
            'eiin too short' => [['eiin' => '12345'], 'eiin'],
            'eiin non numeric' => [['eiin' => 'abcdef'], 'eiin'],
            'unknown division' => [['division' => 'london'], 'division'],
            'unknown board' => [['education_board' => 'oxford'], 'education_board'],
            'empty name' => [['name_en' => ''], 'name_en'],
            'established year too early' => [['established_year' => 1700], 'established_year'],
            'facebook url not a url' => [['facebook_url' => 'not-a-url'], 'facebook_url'],
            'name too long' => [['name_en' => str_repeat('a', 256)], 'name_en'],
        ];
    }

    /**
     * @dataProvider invalidPayloads
     */
    public function test_invalid_payloads_are_rejected(array $payload, string $field): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/settings/institute', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public function test_logo_upload_is_stored_and_returns_an_absolute_url(): void
    {
        $file = UploadedFile::fake()->image('logo.png', 100, 100);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->post('/api/settings/institute', ['_method' => 'PUT', 'logo' => $file])
            ->assertOk();

        $path = Setting::where('key', 'logo')->value('value');

        $this->assertStringStartsWith('settings/', $path);
        Storage::disk('public')->assertExists($path);
        $this->assertStringStartsWith('http', $response->json('data.logo_url'));
        $this->assertStringContainsString('/storage/settings/', $response->json('data.logo_url'));
    }

    public function test_svg_logo_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('logo.svg', 5, 'image/svg+xml');

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/settings/institute', ['_method' => 'PUT', 'logo' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('logo');
    }

    public function test_favicon_over_512kb_is_rejected(): void
    {
        $file = UploadedFile::fake()->create('favicon.png', 600, 'image/png');

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/settings/institute', ['_method' => 'PUT', 'favicon' => $file])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('favicon');
    }

    public function test_replacing_the_logo_deletes_the_old_file(): void
    {
        $first = UploadedFile::fake()->image('logo1.png');

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/settings/institute', ['_method' => 'PUT', 'logo' => $first])
            ->assertOk();

        $oldPath = Setting::where('key', 'logo')->value('value');
        Storage::disk('public')->assertExists($oldPath);

        $second = UploadedFile::fake()->image('logo2.png');

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/settings/institute', ['_method' => 'PUT', 'logo' => $second])
            ->assertOk();

        $newPath = Setting::where('key', 'logo')->value('value');

        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_remove_logo_deletes_the_file_and_nulls_the_setting(): void
    {
        $file = UploadedFile::fake()->image('logo.png');

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/settings/institute', ['_method' => 'PUT', 'logo' => $file])
            ->assertOk();

        $path = Setting::where('key', 'logo')->value('value');
        Storage::disk('public')->assertExists($path);

        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/settings/institute', ['_method' => 'PUT', 'remove_logo' => '1'])
            ->assertOk()
            ->assertJsonPath('data.logo_url', null);

        Storage::disk('public')->assertMissing($path);
        $this->assertDatabaseHas('settings', ['key' => 'logo', 'value' => null]);
    }

    public function test_errors_are_json_even_without_accept_header(): void
    {
        $this->get('/api/settings/institute')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Unauthenticated.']);

        $this->actingAs($this->admin, 'sanctum')
            ->put('/api/settings/institute', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_cache_is_forgotten_so_the_home_page_shows_the_new_name_immediately(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/settings/institute', ['name_en' => 'Brand New School Name'])
            ->assertOk();

        $this->get('/')->assertSee('Brand New School Name');
    }

    public function test_cache_key_is_used(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/settings/institute')->assertOk();

        $this->assertNotNull(Cache::get('settings.institute'));
    }

    public function test_weekly_holidays_default_to_friday_and_can_be_changed(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/settings/institute')
            ->assertOk()->assertJsonPath('data.weekly_holidays', ['friday']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/settings/institute', ['weekly_holidays' => ['saturday', 'friday']])
            ->assertOk()->assertJsonPath('data.weekly_holidays', ['saturday', 'friday']);

        $this->assertSame('["saturday","friday"]', Setting::where('key', 'weekly_holidays')->value('value'));
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/settings/institute')
            ->assertJsonPath('data.weekly_holidays', ['saturday', 'friday']);

        // Form data (the SPA sends multipart) carries the list as weekly_holidays[].
        $this->actingAs($this->admin, 'sanctum')
            ->post('/api/settings/institute', ['_method' => 'PUT', 'weekly_holidays' => ['friday']], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.weekly_holidays', ['friday']);

        // Other keys do not reset it.
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/settings/institute', ['motto' => 'Learn'])
            ->assertJsonPath('data.weekly_holidays', ['friday']);
    }

    public function test_weekly_holidays_are_validated(): void
    {
        foreach ([[], ['funday'], ['friday', 'friday'], 'friday', ['friday', ['x']], range(1, 8)] as $value) {
            $response = $this->actingAs($this->admin, 'sanctum')
                ->putJson('/api/settings/institute', ['weekly_holidays' => $value])
                ->assertUnprocessable();

            // Keyed on the list or on one of its entries.
            $this->assertStringStartsWith('weekly_holidays', array_key_first($response->json('errors')));
        }
    }
}
