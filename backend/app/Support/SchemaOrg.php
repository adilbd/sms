<?php

namespace App\Support;

use App\Models\Page;
use App\Models\Post;
use App\Services\InstituteSettingsService;

/**
 * Builds schema.org JSON-LD structures for the public site.
 */
class SchemaOrg
{
    public static function organization(): array
    {
        $institute = app(InstituteSettingsService::class)->profile();

        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'EducationalOrganization',
            '@id' => url('/').'#organization',
            'name' => $institute['name_en'],
            'alternateName' => $institute['name_bn'],
            'url' => url('/'),
            'logo' => $institute['logo_url'] ?: Seo::absoluteUrl(config('seo.default_image')),
            'email' => $institute['email'],
            'telephone' => $institute['phone'],
            'foundingDate' => $institute['established_year'],
            'address' => self::address($institute),
            'geo' => self::geo($institute),
            'sameAs' => $institute['social'] ?: null,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $institute  Pass the already-resolved profile()
     *                                                to avoid resolving the service again; resolved lazily otherwise.
     */
    public static function address(?array $institute = null): array
    {
        $institute ??= app(InstituteSettingsService::class)->profile();

        return array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $institute['street'],
            'addressLocality' => $institute['upazila'],
            'addressRegion' => $institute['district'],
            'postalCode' => $institute['post_code'],
            // The school this app serves is always in Bangladesh (see CLAUDE.md).
            'addressCountry' => 'BD',
        ]);
    }

    private static function instituteName(): string
    {
        return app(InstituteSettingsService::class)->profile()['name_en'];
    }

    /**
     * @param  array<string, mixed>  $institute
     */
    private static function geo(array $institute): ?array
    {
        if ($institute['latitude'] === null || $institute['longitude'] === null) {
            return null;
        }

        return [
            '@type' => 'GeoCoordinates',
            'latitude' => $institute['latitude'],
            'longitude' => $institute['longitude'],
        ];
    }

    public static function website(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => self::instituteName(),
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
                    'name' => $post->location ?: self::instituteName(),
                    'address' => self::address(),
                ],
                'organizer' => [
                    '@type' => 'EducationalOrganization',
                    'name' => self::instituteName(),
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
                'name' => self::instituteName(),
                'url' => url('/'),
            ],
            'publisher' => ['@id' => url('/').'#organization'],
        ]);
    }

    public static function page(Page $page): array
    {
        return array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'WebPage',
            'name' => $page->title,
            'description' => $page->seoDescription(),
            'url' => $page->url(),
            'datePublished' => $page->published_at?->toIso8601String(),
            'dateModified' => $page->updated_at?->toIso8601String(),
            'publisher' => ['@id' => url('/').'#organization'],
        ]);
    }
}
