<?php

namespace App\Support;

use Illuminate\Support\Str;

class Seo
{
    /**
     * Resolve a configured image (absolute URL or path under /public) to an absolute URL,
     * or null when a local file does not exist.
     */
    public static function absoluteUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return is_file(public_path($path)) ? asset($path) : null;
    }

    /**
     * Canonical URL: current URL without query string, except a page number > 1.
     */
    public static function canonical(): string
    {
        $url = url()->current();
        $page = (int) request()->query('page', 1);

        return $page > 1 ? $url.'?page='.$page : $url;
    }
}
