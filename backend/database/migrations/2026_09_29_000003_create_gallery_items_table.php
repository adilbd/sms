<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gallery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained('galleries')->cascadeOnDelete();
            $table->string('type');
            // Required for 'image' items, restricted so a media row that's part of a
            // gallery can't be deleted out from under it (MediaService checks and
            // reports this as a 409 before the write even reaches the database).
            $table->foreignId('media_id')->nullable()->constrained('media')->restrictOnDelete();
            $table->string('youtube_url', 2048)->nullable();
            $table->string('youtube_id', 11)->nullable();
            $table->string('caption')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['gallery_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_items');
    }
};
