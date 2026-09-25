<?php

return [
    'site_name' => env('SEO_SITE_NAME', env('APP_NAME', 'School Management System')),

    'default_description' => env(
        'SEO_DEFAULT_DESCRIPTION',
        'A caring, future-focused school offering strong academics, arts and sports. Learn about admissions, news and upcoming events.'
    ),

    // Absolute URL or path relative to /public.
    'default_image' => env('SEO_DEFAULT_IMAGE', 'images/og-default.png'),

    'twitter_handle' => env('SEO_TWITTER_HANDLE'),

    'locale' => env('SEO_LOCALE', 'en_US'),

    'organization' => [
        'legal_name' => env('SCHOOL_LEGAL_NAME', env('APP_NAME', 'School Management System')),
        'email' => env('SCHOOL_EMAIL', 'info@example.com'),
        'phone' => env('SCHOOL_PHONE', '+1 555 010 0000'),
        'street' => env('SCHOOL_STREET', '123 Learning Lane'),
        'city' => env('SCHOOL_CITY', 'Springfield'),
        'region' => env('SCHOOL_REGION', ''),
        'postal_code' => env('SCHOOL_POSTAL_CODE', '00000'),
        'country' => env('SCHOOL_COUNTRY', 'US'),
        'founding_year' => env('SCHOOL_FOUNDING_YEAR'),
        'social' => array_filter(explode(',', (string) env('SCHOOL_SOCIAL_LINKS', ''))),
    ],
];
