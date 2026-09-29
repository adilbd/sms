<?php

namespace Tests\Unit;

use App\Repositories\Contracts\SettingRepositoryInterface;
use App\Services\InstituteSettingsService;
use App\Support\InstituteSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Services are unit-tested against a mocked repository interface — no database.
 */
class InstituteSettingsServiceTest extends TestCase
{
    public function test_update_upserts_only_the_provided_keys(): void
    {
        $this->mock(SettingRepositoryInterface::class, function (MockInterface $mock) {
            // Called once to read the current logo/favicon paths, once more after the
            // cache is forgotten to return the fresh values.
            $mock->shouldReceive('valuesFor')->twice()->andReturn([]);
            $mock->shouldReceive('upsertMany')->once()->with(['name_en' => 'New Name', 'phone' => '+880123']);
        });

        app(InstituteSettingsService::class)->update(
            ['name_en' => 'New Name', 'phone' => '+880123'],
            null,
            null,
            false,
            false,
        );
    }

    public function test_update_forgets_the_cached_settings(): void
    {
        // Prime the cache with a stale value.
        Cache::put(
            'settings.institute',
            array_merge(array_fill_keys(InstituteSettings::keyNames(), null), ['name_en' => 'Stale']),
            now()->addMinute()
        );

        $this->mock(SettingRepositoryInterface::class, function (MockInterface $mock) {
            // If update() didn't forget the cache, the trailing all() call would keep
            // reading the stale, primed value and never reach the repository.
            $mock->shouldReceive('valuesFor')->once()->andReturn(['name_en' => 'Fresh']);
            $mock->shouldReceive('upsertMany')->once();
        });

        $result = app(InstituteSettingsService::class)->update(['name_en' => 'Something'], null, null, false, false);

        $this->assertSame('Fresh', $result['name_en']);
    }

    public function test_profile_falls_back_to_config_when_settings_are_unset(): void
    {
        $this->mock(SettingRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('valuesFor')->once()->andReturn([]);
        });

        $profile = app(InstituteSettingsService::class)->profile();
        $org = config('seo.organization');

        $this->assertSame(config('seo.site_name'), $profile['name_en']);
        $this->assertSame($org['phone'], $profile['phone']);
        $this->assertSame($org['email'], $profile['email']);
        $this->assertSame($org['street'], $profile['street']);
        $this->assertNull($profile['name_bn']);
    }

    public function test_service_is_scoped_so_the_container_resolves_the_same_instance(): void
    {
        // The view composer, controllers and SchemaOrg all resolve this service
        // separately; scoped() is what lets their calls share one memoized instance.
        $this->assertSame(app(InstituteSettingsService::class), app(InstituteSettingsService::class));
    }

    public function test_all_and_profile_are_memoized_on_the_instance(): void
    {
        // all()/profile() are read many times per request. The repository (and the
        // cache store behind it) must be hit at most once per instance.
        $this->mock(SettingRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('valuesFor')->once()->andReturn(['name_en' => 'Once Only School']);
        });

        $service = app(InstituteSettingsService::class);

        $service->all();
        $service->all();
        $first = $service->profile();
        $second = $service->profile();

        $this->assertSame('Once Only School', $first['name_en']);
        $this->assertSame($first, $second);
    }

    public function test_update_resets_the_memo_so_a_later_read_on_the_same_instance_is_fresh(): void
    {
        $this->mock(SettingRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('valuesFor')
                ->twice()
                ->andReturn(['name_en' => 'Original Name'], ['name_en' => 'Updated Name']);
            $mock->shouldReceive('upsertMany')->once();
        });

        $service = app(InstituteSettingsService::class);

        // Populate the memo before the update.
        $this->assertSame('Original Name', $service->profile()['name_en']);

        $service->update(['name_en' => 'Updated Name'], null, null, false, false);

        $this->assertSame('Updated Name', $service->all()['name_en']);
        $this->assertSame('Updated Name', $service->profile()['name_en']);
    }

    public function test_update_deletes_the_new_upload_and_keeps_the_old_one_when_the_write_fails(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('settings/old-logo.png', 'old contents');

        $this->mock(SettingRepositoryInterface::class, function (MockInterface $mock) {
            $mock->shouldReceive('valuesFor')->once()->andReturn(['logo' => 'settings/old-logo.png']);
            $mock->shouldReceive('upsertMany')->once()->andThrow(new RuntimeException('database is gone'));
        });

        $service = app(InstituteSettingsService::class);
        $newLogo = UploadedFile::fake()->image('new-logo.png');

        try {
            $service->update([], $newLogo, null, false, false);
            $this->fail('Expected the repository exception to be rethrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('database is gone', $e->getMessage());
        }

        Storage::disk('public')->assertExists('settings/old-logo.png');
        $paths = Storage::disk('public')->allFiles('settings');
        $this->assertSame(['settings/old-logo.png'], $paths, 'The newly uploaded logo should have been deleted.');
    }
}
