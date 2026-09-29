<?php

namespace Tests\Unit;

use App\Support\YouTube;
use Tests\TestCase;

class YouTubeTest extends TestCase
{
    public static function validUrls(): array
    {
        return [
            'watch?v=' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'watch?v= with extra params' => ['https://www.youtube.com/watch?list=PL123&v=dQw4w9WgXcQ&t=42s', 'dQw4w9WgXcQ'],
            'youtu.be' => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'youtu.be with query' => ['https://youtu.be/dQw4w9WgXcQ?t=10', 'dQw4w9WgXcQ'],
            'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'embed with query' => ['https://www.youtube.com/embed/dQw4w9WgXcQ?rel=0', 'dQw4w9WgXcQ'],
            'shorts' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'm.youtube.com' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'bare youtube.com host' => ['https://youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
        ];
    }

    /** @dataProvider validUrls */
    public function test_extracts_the_video_id_from_every_recognized_url_form(string $url, string $expectedId): void
    {
        $this->assertSame($expectedId, YouTube::extractId($url));
    }

    public static function invalidUrls(): array
    {
        return [
            'non-youtube host' => ['https://vimeo.com/123456789'],
            'lookalike host' => ['https://youtube.com.evil.com/watch?v=dQw4w9WgXcQ'],
            'lookalike host with youtu.be prefix' => ['https://youtu.be.evil.com/dQw4w9WgXcQ'],
            'watch without v' => ['https://www.youtube.com/watch?list=PL123'],
            'malformed id too short' => ['https://www.youtube.com/watch?v=short'],
            'malformed id too long' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQtoolong'],
            'not a url' => ['not a url at all'],
            'unsupported path' => ['https://www.youtube.com/channel/UC123'],
            'javascript scheme' => ['javascript:alert(1)'],
        ];
    }

    /** @dataProvider invalidUrls */
    public function test_rejects_non_youtube_hosts_and_malformed_ids(string $url): void
    {
        $this->assertNull(YouTube::extractId($url));
    }

    public function test_embed_url_uses_the_privacy_enhanced_domain(): void
    {
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', YouTube::embedUrl('dQw4w9WgXcQ'));
    }

    public function test_thumbnail_url_uses_the_hqdefault_image(): void
    {
        $this->assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', YouTube::thumbnailUrl('dQw4w9WgXcQ'));
    }
}
