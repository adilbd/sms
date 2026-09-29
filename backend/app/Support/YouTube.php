<?php

namespace App\Support;

/**
 * The single place that recognizes a YouTube URL and extracts its 11-character video
 * id. Used by both the gallery item validation rule (App\Http\Requests\Gallery\Rules\
 * ValidYoutubeUrl) and GalleryService, and by the public gallery page to build the
 * privacy-enhanced embed and thumbnail URLs.
 */
class YouTube
{
    /**
     * Exact host matches only, so a lookalike domain like "youtube.com.evil.com" (whose
     * host is actually "youtube.com.evil.com", not "youtube.com") is rejected.
     */
    private const ALLOWED_HOSTS = [
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'youtu.be',
    ];

    /**
     * Recognizes youtube.com/watch?v=, youtu.be/, youtube.com/embed/, youtube.com/shorts/
     * and the m.youtube.com host, all with or without extra query parameters. Returns
     * null for anything else, including non-YouTube hosts and malformed ids.
     */
    public static function extractId(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || empty($parts['host']) || ! in_array($parts['scheme'] ?? 'https', ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower($parts['host']);

        if (! in_array($host, self::ALLOWED_HOSTS, true)) {
            return null;
        }

        $path = $parts['path'] ?? '';

        if ($host === 'youtu.be') {
            return self::validId(trim($path, '/')) ? trim($path, '/') : null;
        }

        if ($path === '/watch') {
            parse_str($parts['query'] ?? '', $query);
            $id = $query['v'] ?? null;

            return is_string($id) && self::validId($id) ? $id : null;
        }

        if (preg_match('#^/(?:embed|shorts)/([^/?]+)#', $path, $matches) === 1) {
            return self::validId($matches[1]) ? $matches[1] : null;
        }

        return null;
    }

    public static function embedUrl(string $id): string
    {
        return "https://www.youtube-nocookie.com/embed/{$id}";
    }

    public static function thumbnailUrl(string $id): string
    {
        return "https://i.ytimg.com/vi/{$id}/hqdefault.jpg";
    }

    private static function validId(string $id): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9_-]{11}$/', $id);
    }
}
