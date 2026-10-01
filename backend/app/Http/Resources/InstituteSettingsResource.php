<?php

namespace App\Http\Resources;

use App\Support\InstituteSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Wraps the array returned by InstituteSettingsService::all()/update(). logo and
 * favicon are stored disk paths and are deliberately never exposed raw, only as
 * absolute logo_url / favicon_url.
 *
 * @mixin array<string, mixed>
 */
class InstituteSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $settings = $this->resource;

        return [
            'name_en' => $settings['name_en'],
            'name_bn' => $settings['name_bn'],
            'short_name' => $settings['short_name'],
            'motto' => $settings['motto'],
            'established_year' => $settings['established_year'] !== null ? (int) $settings['established_year'] : null,
            'eiin' => $settings['eiin'],
            'institute_code' => $settings['institute_code'],
            'mpo_code' => $settings['mpo_code'],
            'education_board' => $settings['education_board'],
            'logo_url' => $this->fileUrl($settings['logo']),
            'favicon_url' => $this->fileUrl($settings['favicon']),
            'phone' => $settings['phone'],
            'telephone' => $settings['telephone'],
            'email' => $settings['email'],
            'office_hours' => $settings['office_hours'],
            'village' => $settings['village'],
            'ward' => $settings['ward'],
            'union' => $settings['union'],
            'post_office' => $settings['post_office'],
            'post_code' => $settings['post_code'],
            'upazila' => $settings['upazila'],
            'district' => $settings['district'],
            'division' => $settings['division'],
            'latitude' => $settings['latitude'] !== null ? (float) $settings['latitude'] : null,
            'longitude' => $settings['longitude'] !== null ? (float) $settings['longitude'] : null,
            'facebook_url' => $settings['facebook_url'],
            'youtube_url' => $settings['youtube_url'],
            'weekly_holidays' => InstituteSettings::weeklyHolidays($settings['weekly_holidays']),
        ];
    }

    private function fileUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }
}
