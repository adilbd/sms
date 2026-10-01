<?php

namespace App\Services;

use App\Repositories\Contracts\SettingRepositoryInterface;
use App\Support\InstituteSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Reads and writes the "institute" settings group (see App\Support\InstituteSettings)
 * and builds the public-facing profile() every public reader (layout, contact page,
 * SchemaOrg, /api/public/school) uses. See docs/architecture-guidelines.md.
 */
class InstituteSettingsService
{
    /**
     * Public so callers that write to the cache directly (InstituteSettingsSeeder) can
     * invalidate it without duplicating the key.
     */
    public const CACHE_KEY = 'settings.institute';

    /**
     * Memoizes all() and profile() for the lifetime of the instance (see the `scoped()`
     * binding in AppServiceProvider), so a request that reads them repeatedly hits the
     * cache store at most once each. update() resets both.
     *
     * @var array<string, ?string>|null
     */
    private ?array $allMemo = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $profileMemo = null;

    public function __construct(private SettingRepositoryInterface $settings) {}

    /**
     * Every registry key, null when unset. Cached forever; update() forgets the cache.
     *
     * @return array<string, ?string>
     */
    public function all(): array
    {
        return $this->allMemo ??= Cache::rememberForever(self::CACHE_KEY, function () {
            $stored = $this->settings->valuesFor(InstituteSettings::keyNames());

            return array_merge(array_fill_keys(InstituteSettings::keyNames(), null), $stored);
        });
    }

    /**
     * @param  array<string, mixed>  $data  Only the text/select keys that were sent (already validated).
     * @return array<string, ?string> The full, updated set of keys (see all()).
     */
    public function update(array $data, ?UploadedFile $logo, ?UploadedFile $favicon, bool $removeLogo, bool $removeFavicon): array
    {
        $current = $this->all();
        $oldLogoPath = $current['logo'];
        $oldFaviconPath = $current['favicon'];

        $pairs = Arr::only($data, InstituteSettings::keyNames());

        // List-valued keys (weekly_holidays) are stored as JSON.
        $pairs = array_map(fn ($value) => is_array($value) ? json_encode(array_values($value)) : $value, $pairs);

        $newLogoPath = null;
        $newFaviconPath = null;

        if ($logo) {
            $pairs['logo'] = $newLogoPath = $this->storeFile($logo);
        } elseif ($removeLogo) {
            $pairs['logo'] = null;
        }

        if ($favicon) {
            $pairs['favicon'] = $newFaviconPath = $this->storeFile($favicon);
        } elseif ($removeFavicon) {
            $pairs['favicon'] = null;
        }

        try {
            DB::transaction(function () use ($pairs, $oldLogoPath, $oldFaviconPath, $newLogoPath, $newFaviconPath, $removeLogo, $removeFavicon) {
                $this->settings->upsertMany($pairs);

                // Deferred with afterCommit() (rather than run right after the
                // transaction() call returns) so an outer transaction can't delete
                // these files before the write that stops referencing them actually
                // commits.
                DB::afterCommit(function () use ($oldLogoPath, $oldFaviconPath, $newLogoPath, $newFaviconPath, $removeLogo, $removeFavicon) {
                    if ($oldLogoPath && ($newLogoPath || $removeLogo)) {
                        Storage::disk('public')->delete($oldLogoPath);
                    }

                    if ($oldFaviconPath && ($newFaviconPath || $removeFavicon)) {
                        Storage::disk('public')->delete($oldFaviconPath);
                    }
                });
            });
        } catch (\Throwable $e) {
            // The row was never saved, so the newly uploaded file(s) would otherwise
            // be orphaned on disk.
            if ($newLogoPath) {
                Storage::disk('public')->delete($newLogoPath);
            }

            if ($newFaviconPath) {
                Storage::disk('public')->delete($newFaviconPath);
            }

            throw $e;
        }

        $this->allMemo = null;
        $this->profileMemo = null;
        Cache::forget(self::CACHE_KEY);

        return $this->all();
    }

    /**
     * The weekdays with no school (lowercase English names), `['friday']` until an admin
     * sets them. Read by AttendanceService, never from config.
     *
     * @return list<string>
     */
    public function weeklyHolidays(): array
    {
        return InstituteSettings::weeklyHolidays($this->all()['weekly_holidays'] ?? null);
    }

    /**
     * The effective public values: every reader (layout, contact page, SchemaOrg,
     * /api/public/school) uses this, never all() or config('seo.*') directly. Falls
     * back field-by-field to config('seo.*') so an empty settings table renders
     * exactly as it did before this module existed.
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        return $this->profileMemo ??= $this->buildProfile();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildProfile(): array
    {
        $settings = $this->all();
        $org = config('seo.organization');

        return [
            'name_en' => $settings['name_en'] ?: config('seo.site_name'),
            'name_bn' => $settings['name_bn'],
            'short_name' => $settings['short_name'],
            'motto' => $settings['motto'],
            'established_year' => $this->toInt($settings['established_year']) ?? $this->toInt($org['founding_year']),
            'eiin' => $settings['eiin'],
            'institute_code' => $settings['institute_code'],
            'mpo_code' => $settings['mpo_code'],
            'education_board' => $settings['education_board'],
            'logo_url' => $this->fileUrl($settings['logo']),
            'favicon_url' => $this->fileUrl($settings['favicon']),
            'phone' => $settings['phone'] ?: $org['phone'],
            'telephone' => $settings['telephone'],
            'email' => $settings['email'] ?: $org['email'],
            'office_hours' => $settings['office_hours'],
            'village' => $settings['village'],
            'ward' => $settings['ward'],
            'union' => $settings['union'],
            'post_office' => $settings['post_office'],
            'post_code' => $settings['post_code'] ?: $org['postal_code'],
            'upazila' => $settings['upazila'] ?: $org['city'],
            'district' => $settings['district'] ?: $org['region'],
            'division' => $settings['division'],
            'latitude' => $this->toFloat($settings['latitude']),
            'longitude' => $this->toFloat($settings['longitude']),
            'facebook_url' => $settings['facebook_url'],
            'youtube_url' => $settings['youtube_url'],
            // Composed for the footer, contact page and SchemaOrg's streetAddress.
            // No 1:1 settings key maps to the old flat "street" config value, so this
            // only falls back to it when village, ward and union are all unset.
            'street' => $this->composeStreet($settings) ?: $org['street'],
            'social' => $this->composeSocial($settings) ?: $org['social'],
        ];
    }

    private function composeStreet(array $settings): ?string
    {
        $parts = array_filter([
            $settings['village'],
            $settings['ward'] ? "Ward {$settings['ward']}" : null,
            $settings['union'],
        ]);

        return $parts ? implode(', ', $parts) : null;
    }

    /**
     * @return list<string>
     */
    private function composeSocial(array $settings): array
    {
        return array_values(array_filter([$settings['facebook_url'], $settings['youtube_url']]));
    }

    private function fileUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    private function storeFile(UploadedFile $file): string
    {
        return $file->storeAs('settings', Str::uuid()->toString().'.'.$file->extension(), 'public');
    }

    private function toInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function toFloat(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
