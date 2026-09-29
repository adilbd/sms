<?php

namespace Database\Seeders;

use App\Models\Setting;
use App\Services\InstituteSettingsService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Sample Bangla/English institute settings so a fresh install shows a real school
 * identity instead of the US placeholders in config/seo.php. Uses firstOrCreate per
 * key, so re-running it (or seeding after an admin has already edited some fields)
 * never overwrites an existing row.
 */
class InstituteSettingsSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->values() as $key => $value) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value]);
        }

        // Writing rows directly with the model bypasses InstituteSettingsService::update(),
        // so forget the cache here too, or a request served (and cached) before seeding
        // would keep showing the empty profile.
        Cache::forget(InstituteSettingsService::CACHE_KEY);
    }

    /**
     * @return array<string, string>
     */
    private function values(): array
    {
        return [
            'name_en' => 'Green View High School',
            'name_bn' => 'গ্রীন ভিউ উচ্চ বিদ্যালয়',
            'short_name' => 'GVHS',
            'motto' => 'Knowledge is Light',
            'established_year' => '1985',
            'eiin' => '123456',
            'institute_code' => '12345',
            'mpo_code' => '6001234501',
            'education_board' => 'dhaka',
            'phone' => '+880 1711-000000',
            'telephone' => '02-9887766',
            'email' => 'info@greenviewschool.edu.bd',
            'office_hours' => 'Sunday–Thursday, 9:00 AM – 4:00 PM',
            'village' => 'Bosila',
            'ward' => '7',
            'union' => 'Kachukhet',
            'post_office' => 'Mohammadpur',
            'post_code' => '1207',
            'upazila' => 'Mohammadpur',
            'district' => 'Dhaka',
            'division' => 'dhaka',
            'latitude' => '23.7639',
            'longitude' => '90.3489',
            'facebook_url' => 'https://facebook.com/greenviewschool',
            'youtube_url' => 'https://youtube.com/@greenviewschool',
        ];
    }
}
