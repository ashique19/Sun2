<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();             // generated on import
            $table->string('sku', 64)->nullable();
            $table->mediumText('description')->nullable();     // legacy product_detail
            $table->mediumText('description_bn')->nullable();  // legacy product_detail_bn
            $table->decimal('price', 12, 2)->default(0);
            $table->decimal('compare_at_price', 12, 2)->nullable();
            $table->decimal('purchase_price', 12, 2)->default(0);
            $table->integer('stock_quantity')->default(0);     // was tinyint (max 127 bug)
            $table->boolean('is_published')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_new')->default(false);
            $table->boolean('is_best_seller')->default(false);
            $table->smallInteger('display_order')->default(0);
            $table->string('video_url')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(0);
            $table->integer('review_count')->default(0);
            $table->string('meta_title')->nullable();
            $table->text('meta_keyword')->nullable();
            $table->text('meta_description')->nullable();
            $table->unsignedBigInteger('legacy_id')->nullable()->index();
            $table->timestamps();

            $table->index(['is_published', 'category_id', 'display_order'], 'idx_prod_browse');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
