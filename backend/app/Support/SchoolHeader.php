<?php

namespace App\Support;

/**
 * The school's identity block printed on certificates and ID cards, built from
 * InstituteSettingsService::profile(). Stateless, like Seo and SchemaOrg.
 */
class SchoolHeader
{
    /**
     * @param  array<string, mixed>  $profile
     * @return array{name_en: ?string, name_bn: ?string, address: ?string, eiin: ?string, phone: ?string, logo_url: ?string}
     */
    public static function from(array $profile): array
    {
        return [
            'name_en' => $profile['name_en'] ?? null,
            'name_bn' => $profile['name_bn'] ?? null,
            'address' => collect([
                $profile['village'] ?? null, $profile['post_office'] ?? null, $profile['upazila'] ?? null, $profile['district'] ?? null,
            ])->filter()->implode(', ') ?: null,
            'eiin' => $profile['eiin'] ?? null,
            'phone' => $profile['phone'] ?? null,
            'logo_url' => $profile['logo_url'] ?? null,
        ];
    }
}
