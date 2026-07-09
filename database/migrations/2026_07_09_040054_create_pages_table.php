<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // CMS static pages (privacy, terms, about, ...).
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('details')->nullable();
            $table->string('meta_tag_title')->nullable();
            $table->string('meta_tag_description')->nullable();
            $table->string('meta_tag_keywords')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
