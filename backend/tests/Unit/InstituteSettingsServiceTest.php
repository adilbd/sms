<?php

namespace Tests\Unit;

use App\Repositories\Contracts\SettingRepositoryInterface;
use App\Services\InstituteSettingsService;
use App\Support\InstituteSettings;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
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
}
