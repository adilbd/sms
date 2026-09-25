<?php

namespace Tests\Unit;

use App\Support\PostBody;
use Tests\TestCase;

class PostBodyTest extends TestCase
{
    public function test_from_markdown_renders_headings(): void
    {
        $this->assertSame('<h2>x</h2>', PostBody::fromMarkdown('## x'));
    }

    public function test_plain_text_strips_tags_and_decodes_entities(): void
    {
        $text = PostBody::plainText('<p>Fish &amp; chips <strong>rock</strong></p>');

        $this->assertSame('Fish & chips rock', $text);
    }

    public function test_plain_text_collapses_whitespace_and_limits_length(): void
    {
        $text = PostBody::plainText('<p>Hello   world</p>', 5);

        $this->assertSame('Hello...', $text);
    }

    public function test_is_empty_is_true_for_an_empty_paragraph(): void
    {
        $this->assertTrue(PostBody::isEmpty('<p></p>'));
        $this->assertTrue(PostBody::isEmpty(''));
        $this->assertTrue(PostBody::isEmpty('<p>   </p>'));
    }

    public function test_is_empty_is_false_for_an_image_only_body(): void
    {
        $this->assertFalse(PostBody::isEmpty('<img src="/storage/posts/a.jpg" alt="A">'));
    }

    public function test_is_empty_is_false_for_an_iframe_only_body(): void
    {
        $this->assertFalse(PostBody::isEmpty('<iframe src="https://www.youtube-nocookie.com/embed/abc"></iframe>'));
    }

    public function test_is_empty_is_false_when_there_is_text(): void
    {
        $this->assertFalse(PostBody::isEmpty('<p>Hello</p>'));
    }

    public function test_sanitize_strips_scripts_and_event_attributes(): void
    {
        $clean = PostBody::sanitize('<script>alert(1)</script><p onclick="alert(1)">Hi</p>');

        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringContainsString('Hi', $clean);
    }

    public function test_sanitize_strips_javascript_links_and_h1(): void
    {
        $clean = PostBody::sanitize('<a href="javascript:alert(1)">bad</a><h1>Title</h1>');

        $this->assertStringNotContainsString('javascript:', $clean);
        $this->assertStringNotContainsString('<h1', $clean);
    }

    public function test_sanitize_keeps_images_and_allowed_video_embeds(): void
    {
        $clean = PostBody::sanitize(
            '<img src="/storage/posts/a.jpg" alt="A">'.
            '<iframe src="https://www.youtube-nocookie.com/embed/abc"></iframe>'.
            '<iframe src="https://player.vimeo.com/video/123"></iframe>'
        );

        $this->assertStringContainsString('<img', $clean);
        $this->assertStringContainsString('https://www.youtube-nocookie.com/embed/abc', $clean);
        $this->assertStringContainsString('https://player.vimeo.com/video/123', $clean);
    }

    public function test_sanitize_strips_iframes_from_hosts_not_on_the_allowlist(): void
    {
        $clean = PostBody::sanitize('<iframe src="https://evil.com/x"></iframe>');

        $this->assertStringNotContainsString('evil.com', $clean);
        $this->assertStringNotContainsString('<iframe', $clean);
    }

    public function test_sanitize_keeps_target_blank_links_and_adds_noreferrer(): void
    {
        // The Tiptap link toolbar sets target="_blank" (RichTextEditor.vue). HTMLPurifier
        // strips `target` unless the value is explicitly allowed (Attr.AllowedFrameTargets).
        $clean = PostBody::sanitize('<a href="https://example.com" target="_blank">example</a>');

        $this->assertStringContainsString('target="_blank"', $clean);
        $this->assertMatchesRegularExpression('/rel="[^"]*noopener[^"]*"/', $clean);
        $this->assertMatchesRegularExpression('/rel="[^"]*noreferrer[^"]*"/', $clean);
    }
}
