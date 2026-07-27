<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_post_publications', function (Blueprint $table) {
            $table->id();

            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();

            $table->string('channel', 20); // facebook | instagram

            $table->string('external_id', 128)->nullable();
            $table->text('external_url')->nullable();

            // pending | success | failed
            $table->string('status', 20)->default('pending');

            $table->text('error')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(['social_post_id', 'channel', 'published_at'], 'idx_social_post_pubs');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_publications');
    }
};

