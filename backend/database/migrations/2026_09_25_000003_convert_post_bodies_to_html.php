<?php

use App\Models\Post;
use App\Support\PostBody;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Post bodies were Markdown, rendered to HTML on every request. From this release
     * they are sanitized HTML, rendered once and stored as-is. Convert every existing
     * row so the single render path in resources/views/public/posts/show.blade.php
     * (`{!! $post->body !!}`) keeps working for rows written before this change.
     */
    public function up(): void
    {
        Post::query()->select(['id', 'body'])->chunkById(100, function ($posts) {
            foreach ($posts as $post) {
                $post->timestamps = false;
                $post->update(['body' => PostBody::fromMarkdown($post->body)]);
            }
        });
    }

    /**
     * Not reversible: converting HTML back to the original Markdown would be lossy,
     * and the data no longer exists once this migration has run.
     */
    public function down(): void
    {
        // Intentionally left blank.
    }
};
