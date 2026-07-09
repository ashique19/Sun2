<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blogs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();            // legacy link
            $table->string('banner_photo')->nullable();
            $table->text('details')->nullable();
            $table->boolean('status')->default(false);   // draft/published
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('blog_slides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('subtitle')->nullable();
            $table->string('slide_photo')->nullable();
            $table->timestamps();
        });

        Schema::create('related_blogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
            $table->foreignId('related_blog_id')->constrained('blogs')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['blog_id', 'related_blog_id']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('blog_id')->constrained()->cascadeOnDelete();
            $table->text('body')->nullable();
            $table->boolean('status')->default(false);
            $table->boolean('is_reply')->default(false);
            $table->foreignId('parent_id')->nullable()->constrained('comments')->nullOnDelete(); // legacy comment_id
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
        Schema::dropIfExists('related_blogs');
        Schema::dropIfExists('blog_slides');
        Schema::dropIfExists('blogs');
    }
};
