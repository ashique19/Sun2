<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_post_products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();

            $table->smallInteger('sort_order')->default(0);

            // Snapshot paths used at compose/publish time so re-publish stays stable
            // even if product media later changes.
            $table->string('thumb_snapshot_path')->nullable();
            $table->string('priced_snapshot_path')->nullable();

            $table->timestamps();

            $table->unique(['social_post_id', 'product_id']);
            $table->index(['social_post_id', 'sort_order'], 'idx_social_post_products_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_products');
    }
};

