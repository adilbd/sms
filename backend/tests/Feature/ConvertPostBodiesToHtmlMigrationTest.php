<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConvertPostBodiesToHtmlMigrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Regression test: an earlier version of this migration loaded posts through the
     * Post model with only `id` and `body` selected, so the `saving` hook crashed with
     * a TypeError trying to build a slug from a null title. Running the migration class
     * directly against a raw Markdown row must not touch the slug or title at all.
     */
    public function test_migration_converts_markdown_bodies_to_html_without_touching_slug_or_title(): void
    {
        $id = DB::table('posts')->insertGetId([
            'type' => 'news',
            'title' => 'Legacy Markdown Post',
            'slug' => 'legacy-markdown-post',
            'body' => "## Heading\n\n**bold** text.",
            'is_published' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        (require database_path('migrations/2026_09_25_000003_convert_post_bodies_to_html.php'))->up();

        $post = DB::table('posts')->find($id);

        $this->assertSame('Legacy Markdown Post', $post->title);
        $this->assertSame('legacy-markdown-post', $post->slug);
        $this->assertStringContainsString('<h2>Heading</h2>', $post->body);
        $this->assertStringContainsString('<strong>bold</strong>', $post->body);
        $this->assertStringNotContainsString('##', $post->body);
    }
}
