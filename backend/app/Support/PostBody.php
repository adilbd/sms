<?php

namespace App\Support;

use Illuminate\Support\Str;
use Mews\Purifier\Facades\Purifier;

/**
 * Stateless helper for post bodies, which are stored as sanitized HTML (see the
 * "Post model behavior" note in CLAUDE.md). Needs no repository.
 */
class PostBody
{
    /**
     * Sanitize untrusted HTML (for example, from the Tiptap editor) with the post_body
     * HTMLPurifier profile. See config/purifier.php for the allowlist.
     */
    public static function sanitize(string $html): string
    {
        return Purifier::clean($html, 'post_body');
    }

    /**
     * Render legacy Markdown to HTML and sanitize it. Used by the data migration that
     * converts existing bodies once, and available for any other Markdown source.
     */
    public static function fromMarkdown(string $markdown): string
    {
        $html = trim((string) Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]));

        // A leading `# Title` in legacy Markdown becomes an <h1>, but the sanitizer
        // strips h1 outright (PublicSeoTest requires exactly one <h1> per page, owned
        // by the page template). Demote it to <h2> instead of losing the heading.
        $html = preg_replace('#<(/?)h1\b#i', '<$1h2', $html);

        return static::sanitize($html);
    }

    /**
     * Plain-text rendering used for excerpts and meta descriptions: tags stripped,
     * entities decoded, whitespace collapsed, then limited to $limit characters.
     */
    public static function plainText(string $html, int $limit = 160): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('/\s+/', ' ', $text));

        return Str::limit($text, $limit);
    }

    /**
     * True when the body has no text and no media (img or iframe), so it would render
     * as nothing on the public page.
     */
    public static function isEmpty(string $html): bool
    {
        if (static::plainText($html, PHP_INT_MAX) !== '') {
            return false;
        }

        return ! preg_match('/<(img|iframe)\b/i', $html);
    }
}
