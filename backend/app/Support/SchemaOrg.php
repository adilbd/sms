<?php

namespace App\Support;

use App\Models\Post;

/**
 * Builds schema.org JSON-LD structures for the public site.
 */
class SchemaOrg
{
    public static function organization(): array
    {
        $org = config('seo.organization');

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'EducationalOrganization',
            '@id' => url('/').'#organization',
            'name' => config('seo.site_name'),
            'legalName' => $org['legal_name'],
            'url' => url('/'),
            'logo' => Seo::absoluteUrl(config('seo.default_image')),
            'email' => $org['email'],
            'telephone' => $org['phone'],
            'foundingDate' => $org['founding_year'],
            'address' => self::address(),
            'sameAs' => $org['social'] ?: null,
        ]);
    }

    public static function address(): array
    {
        $org = config('seo.organization');

        return array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $org['street'],
            'addressLocality' => $org['city'],
            'addressRegion' => $org['region'],
            'postalCode' => $org['postal_code'],
            'addressCountry' => $org['country'],
        ]);
    }

    public static function website(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => config('seo.site_name'),
            'url' => url('/'),
            'publisher' => ['@id' => url('/').'#organization'],
        ];
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $items  [name, url] pairs
     */
    public static function breadcrumbs(array $items): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => collect($items)->values()->map(fn ($item, $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $item[0],
                'item' => $item[1],
            ])->all(),
        ];
    }

    public static function post(Post $post): array
    {
        $image = $post->coverImageUrl() ?? Seo::absoluteUrl(config('seo.default_image'));

        if ($post->type === Post::TYPE_EVENT) {
            return array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Event',
                'name' => $post->title,
                'description' => $post->seoDescription(),
                'url' => $post->url(),
                'image' => $image ? [$image] : null,
                'startDate' => $post->event_starts_at?->toIso8601String(),
                'endDate' => $post->event_ends_at?->toIso8601String(),
                'eventStatus' => 'https://schema.org/EventScheduled',
                'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
                'location' => [
                    '@type' => 'Place',
                    'name' => $post->location ?: config('seo.site_name'),
                    'address' => self::address(),
                ],
                'organizer' => [
                    '@type' => 'EducationalOrganization',
                    'name' => config('seo.site_name'),
                    'url' => url('/'),
                ],
            ]);
        }

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'NewsArticle',
            'headline' => $post->title,
            'description' => $post->seoDescription(),
            'mainEntityOfPage' => $post->url(),
            'image' => $image ? [$image] : null,
            'datePublished' => $post->published_at?->toIso8601String(),
            'dateModified' => $post->updated_at?->toIso8601String(),
            'author' => [
                '@type' => 'Organization',
                'name' => config('seo.site_name'),
                'url' => url('/'),
            ],
            'publisher' => ['@id' => url('/').'#organization'],
        ]);
    }
}
