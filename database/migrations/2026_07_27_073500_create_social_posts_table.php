<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_posts', function (Blueprint $table) {
            $table->id();

            $table->longText('body');

            // Which per-product image to use as the source.
            $table->string('image_source', 20)->default('thumb'); // thumb | priced

            // How to assemble/publish.
            $table->string('layout', 20)->default('album'); // album | collage

            // Snapshot paths used for homepage/on-site rendering.
            $table->string('thumbnail_path')->nullable(); // homepage card thumbnail
            $table->string('collage_path')->nullable(); // composed collage image (when layout=collage)

            // draft | published | failed
            $table->string('status', 30)->default('draft');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'created_at'], 'idx_social_posts_latest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_posts');
    }
};

