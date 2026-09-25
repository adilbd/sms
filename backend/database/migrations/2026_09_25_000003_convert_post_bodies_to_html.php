<?php

use App\Support\PostBody;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Post bodies were Markdown, rendered to HTML on every request. From this release
     * they are sanitized HTML, rendered once and stored as-is. Convert every existing
     * row so the single render path in resources/views/public/posts/show.blade.php
     * (`{!! $post->body !!}`) keeps working for rows written before this change.
     *
     * Goes through the query builder, not the Post model: loading only `id` and `body`
     * through Eloquent would still fire the model's `saving` hook on `update()`, which
     * regenerates the slug from the (unselected, therefore null) title.
     */
    public function up(): void
    {
        DB::table('posts')->select(['id', 'body'])->orderBy('id')->chunkById(100, function ($posts) {
            foreach ($posts as $post) {
                DB::table('posts')->where('id', $post->id)->update([
                    'body' => PostBody::fromMarkdown($post->body),
                ]);
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
